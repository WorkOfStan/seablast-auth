<?php

declare(strict_types=1);

namespace Seablast\Auth;

use Seablast\Auth\AuthConstant;
use Seablast\Seablast\SeablastConfiguration;
use Seablast\Seablast\SeablastConstant;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tracy\Debugger;
use Tracy\ILogger;
use Webmozart\Assert\Assert;

/**
 * Generic mail sender built on top of Symfony Mailer.
 *
 * Usage:
 *   $sendMail = new MailOut($seablastConfiguration);
 *   // where dsn builds 'smtp://smtp.example.com:587' and default from is 'noreply@example.com'
 *   $sendMail->send(
 *       to: 'user@example.com',
 *       subject: 'Login link',
 *       textBody: "Open this URL: https://app.example.com/?token=XYZ",
 *       options: [
 *           'cc'   => ['cc1@example.com', 'cc2@example.com'], // optional
 *           'bcc'  => 'audit@example.com',                    // optional, can be string or array
 *           'html' => '<p>Open this URL: <a href="https://app.example.com/?token=XYZ">Login</a></p>', // optional
 *           // 'replyTo' => 'support@example.com',           // optional
 *           // 'from'    => 'custom-from@example.com',       // optional override of defaultFrom
 *           // 'priority'=> Email::PRIORITY_HIGH,            // optional (1..5), default normal
 *       ]
 *   );
 */
class MailOut
{
    use \Nette\SmartObject;

    /** @var SeablastConfiguration */
    private $configuration;
    /** @var Address Default "From" address used when 'from' option is not provided */
    private $defaultFrom;
    /** @var MailerInterface */
    private $mailer;

    /**
     * @param SeablastConfiguration $configuration
     */
    public function __construct(SeablastConfiguration $configuration)
    {
        $this->configuration = $configuration;
        $dsn = 'smtp://' . $this->configuration->getString(SeablastConstant::SB_SMTP_HOST) . ':'
            . (string) $this->configuration->getInt(SeablastConstant::SB_SMTP_PORT);
        Assert::stringNotEmpty(
            $this->configuration->getString(SeablastConstant::FROM_MAIL_ADDRESS),
            'Default "from" address `SeablastConstant::FROM_MAIL_ADDRESS` must be a non-empty string.'
        );
        if ($this->configuration->exists(AuthConstant::FROM_MAIL_NAME)) {
            $this->defaultFrom = new Address(
                $this->configuration->getString(SeablastConstant::FROM_MAIL_ADDRESS),
                $this->configuration->getString(AuthConstant::FROM_MAIL_NAME)
            );
        } else {
            $this->defaultFrom = new Address($this->configuration->getString(SeablastConstant::FROM_MAIL_ADDRESS));
        }

        $transport = Transport::fromDsn($dsn);
        $this->mailer = new Mailer($transport);
    }

    /**
     * Send an email.
     *
     * @param string               $to       Recipient address (příjemce)
     * @param string               $subject  Subject (předmět)
     * @param string               $textBody Plain-text body (textové tělo zprávy)
     * @param array<string,mixed>  $options  Optional options:
     *                                       - 'cc'   => string|string[]  (optional)
     *                                       - 'bcc'  => string|string[]  (optional)
     *                                       - 'html' => string           (optional HTML version of the body)
     *                                       - 'from' => string           (optional override of defaultFrom)
     *                                       - 'replyTo' => string        (optional)
     *                                       - 'priority' => int          (1..5) (optional)
     */
    public function send(string $to, string $subject, string $textBody, array $options = []): void
    {
        Assert::email($to, 'Invalid "to" e-mail address: %s');
        Assert::stringNotEmpty($subject, 'Subject must be a non-empty string.');
        //        Assert::string($textBody, 'Text body must be a string.');

        $from = (isset($options['from']) && is_scalar($options['from']))
            ? new Address((string) $options['from']) : $this->defaultFrom;
        // Assert::email($from, 'Invalid "from" e-mail address: %s'); // Address does its own email validation

        if (!$this->configuration->flag->status(SeablastConstant::USER_MAIL_ENABLED)) {
            Debugger::log(
                "Config blocks email sending> subject: `{$subject}` from: `" . $from->getAddress() . "` to: `{$to}`",
                ILogger::WARNING
            );
            return;
        }

        $email = (new Email())
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->text($textBody);

        // HTML version (optional)
        if (isset($options['html'])) {
            Assert::string($options['html'], 'Option "html" must be a string.');
            $email->html($options['html']);
        }

        // CC (carbon copy) – can be string or array
        if (isset($options['cc']) && (is_string($options['cc']) || is_array($options['cc']))) {
            if (is_array($options['cc'])) {
                Assert::allString($options['cc']);
            }
            foreach ($this->normalizeEmails($options['cc']) as $cc) {
                $email->addCc($cc);
            }
        }

        // BCC (blind carbon copy) – can be string or array
        if (isset($options['bcc']) && (is_string($options['bcc']) || is_array($options['bcc']))) {
            if (is_array($options['bcc'])) {
                Assert::allString($options['bcc']);
            }
            foreach ($this->normalizeEmails($options['bcc']) as $bcc) {
                $email->addBcc($bcc);
            }
        }

        // Reply-To (odpovědět komu)
        if (isset($options['replyTo'])) {
            Assert::email($options['replyTo'], 'Invalid "replyTo" e-mail address: %s');
            $email->replyTo($options['replyTo']);
        }

        // Priority (priorita 1..5)
        if (isset($options['priority']) && is_scalar($options['priority'])) {
            $prio = (int) $options['priority'];
            Assert::range($prio, 1, 5, 'Priority must be between 1 and 5.');
            $email->priority($prio);
        }

        $this->mailer->send($email);
        Debugger::log("SENDING " . $from->getAddress() . " -> {$to}/{$subject}", ILogger::INFO);
    }

    /**
     * Normalize string|string[] into array of valid emails.
     *
     * @param string|string[] $value
     * @return string[]
     */
    private function normalizeEmails($value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        //Assert::isArray($value, 'Expected string or array of e-mails.');
        $value = array_values(array_filter($value, static function ($v): bool {
            return //is_string($v) &&
            $v !== '';
        }));
        foreach ($value as $addr) {
            Assert::email($addr, 'Invalid e-mail in list: %s');
        }
        return $value;
    }
}

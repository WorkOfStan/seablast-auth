<?php

declare(strict_types=1);

namespace Seablast\Auth;

use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Webmozart\Assert\Assert;

/**
 * Generic mail sender built on top of Symfony Mailer.
 *
 * Usage:
 *   $sendMail = new MailOut('smtp://smtp.example.com:587', 'noreply@example.com');
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

    /** @var string Default "From" address used when 'from' option is not provided */
    private $defaultFrom;
    /** @var MailerInterface */
    private $mailer;

    /**
     * @param string|MailerInterface $dsnOrMailer  DSN string like 'smtp://host:port' OR preconfigured MailerInterface
     * @param string                 $defaultFrom  Fallback sender e-mail address
     */
    public function __construct($dsnOrMailer, string $defaultFrom)
    {
        Assert::stringNotEmpty($defaultFrom, 'Default "from" address must be a non-empty string.');
        $this->defaultFrom = $defaultFrom;

        if ($dsnOrMailer instanceof MailerInterface) {
            $this->mailer = $dsnOrMailer;
        } else {
            Assert::stringNotEmpty($dsnOrMailer, 'Mailer DSN must be a non-empty string or a MailerInterface.');
            $transport = Transport::fromDsn($dsnOrMailer);
            $this->mailer = new Mailer($transport);
        }
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
            ? (string) $options['from'] : $this->defaultFrom;
        Assert::email($from, 'Invalid "from" e-mail address: %s');

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
            if(is_array($options['cc'])) {
                Assert::allString($options['cc']);
            }
            foreach ($this->normalizeEmails($options['cc']) as $cc) {
                $email->addCc($cc);
            }
        }

        // BCC (blind carbon copy) – can be string or array
        if (isset($options['bcc']) && (is_string($options['bcc']) || is_array($options['bcc']))) {
            if(is_array($options['bcc'])) {
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
    }

    /**
     * Normalize string|string[] into array of valid e-mails.
     * (Normalizuje string nebo pole na pole validních e-mailů.)
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

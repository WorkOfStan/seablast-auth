<?php

declare(strict_types=1);

namespace Seablast\Auth;

/**
 * @api
 * Strings MUST NOT start with SB to avoid unintended value collision
 */
class AuthConstant
{
    /**
     * @var string Subject of login email
     */
    public const SUBJECT_EMAIL_LOGIN = 'AuthApp:SUBJECT_EMAIL_LOGIN';
    /**
     * @var string Subject of registration email
     */
    public const SUBJECT_EMAIL_REGISTRATION = 'AuthApp:SUBJECT_EMAIL_REGISTRATION';
    /**
     * @var string Text of login email (%URL% will be replace by the login URL)
     */
    public const TEXT_EMAIL_LOGIN = 'AuthApp:TEXT_EMAIL_LOGIN';
    /**
     * @var string Text of registration email (%URL% will be replace by the activation URL)
     */
    public const TEXT_EMAIL_REGISTRATION = 'AuthApp:TEXT_EMAIL_REGISTRATION';
}

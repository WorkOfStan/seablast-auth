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
     * @var string Facebook App ID
     */
    public const FACEBOOK_APP_ID = 'AuthApp:FACEBOOK_APP_ID';
    /**
     * @var string Flag: Social login custom button instead of native one
     */
    public const FLAG_SOCIAL_LOGIN_CUSTOM = 'AuthApp:FLAG_SOCIAL_LOGIN_CUSTOM';
    /**
     * @var string Flag: Use or not use a social login
     */
    public const FLAG_USE_SOCIAL_LOGIN = 'AuthApp:FLAG_USE_SOCIAL_LOGIN';
    /**
     * @var string Google Client ID
     */
    public const GOOGLE_CLIENT_ID = 'AuthApp:GOOGLE_CLIENT_ID';
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
    /**
     * @var string Route to the page where user can log in/log out
     */
    public const USER_ROUTE = 'AuthApp:USER_ROUTE';
}

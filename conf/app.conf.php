<?php

/**
 * SeablastConfiguration structure accepts all values, however only the expected ones are processed.
 * The usage of constants defined in the SeablastConstant class is encouraged for the sake of hinting within IDE.
 */

use Seablast\Auth\AuthConstant;
use Seablast\Seablast\SeablastConfiguration;
use Seablast\Seablast\SeablastConstant;

return static function (SeablastConfiguration $SBConfig): void {
    $SBConfig->flag
        //->activate(SeablastConstant::FLAG_WEB_RUNNING)
        ->activate(AuthConstant::FLAG_USE_SOCIAL_LOGIN) // actual social login requires AuthApp:..social.._ID
        // - AuthApp:GOOGLE_CLIENT_ID
        // - AuthApp:FACEBOOK_APP_ID
    ;
    $SBConfig
        // /api/social-login is a single end-point , differentiation by provider is done in the parameter provider;
        // so far just facebook, google
        ->setArrayArrayString(
            SeablastConstant::APP_MAPPING,
            '/api/social-login',
            [
                'model' => '\Seablast\Auth\Models\ApiSocialLoginModel',
            ]
        )
        // Database
        //    ->setString(SeablastConstant::SB_PHINX_ENVIRONMENT, 'testing')
        // Expected route for the page where user can log-in/log-out
        ->setString(AuthConstant::USER_ROUTE, '/user')
        ->setArrayArrayString(
            SeablastConstant::APP_MAPPING,
            '/user', // page slug, i.e. URL representation
            [
                'template' => 'user', // template used by the View component
                'model' => '\Seablast\Auth\UserModel',
            ]
        )
    ;
};

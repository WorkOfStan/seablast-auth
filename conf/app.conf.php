<?php

/**
 * SeablastConfiguration structure accepts all values, however only the expected ones are processed.
 * The usage of constants defined in the SeablastConstant class is encouraged for the sake of hinting within IDE.
 */

use Seablast\Auth\AuthConstant;
use Seablast\Seablast\SeablastConfiguration;
use Seablast\Seablast\SeablastConstant;

return static function (SeablastConfiguration $SBConfig): void {
    //$SBConfig->flag
    //    ->activate(SeablastConstant::FLAG_WEB_RUNNING)
    //;
    $SBConfig
//        ->setArrayArrayString(// TODO - move to Seablast? or Seablast/Auth?
//            SeablastConstant::APP_MAPPING,
//            '/api/facebook-login',
//            [
//                'model' => '\Seablast\Auth\Models\ApiFacebookLoginModel',
//            ]
//        )
//        ->setArrayArrayString(// TODO - move to Seablast? or Seablast/Auth?
//            SeablastConstant::APP_MAPPING,
//            '/api/google-login',
//            [
//                'model' => '\Seablast\Auth\Models\ApiGoogleLoginModel',
//            ]
//        )
        // Database
        //    ->setString(SeablastConstant::SB_PHINX_ENVIRONMENT, 'testing')
        // Expected route for the page where user can log-in/log-out
        ->setString(AuthConstant::USER_ROUTE, '/user')
    ;
};

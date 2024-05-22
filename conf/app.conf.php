<?php

/**
 * SeablastConfiguration structure accepts all values, however only the expected ones are processed.
 * The usage of constants defined in the SeablastConstant class is encouraged for the sake of hinting within IDE.
 */

use Seablast\Seablast\SeablastConfiguration;
use Seablast\Seablast\SeablastConstant;

return static function (SeablastConfiguration $SBConfig): void {
    //$SBConfig->flag
    //    ->activate(SeablastConstant::FLAG_WEB_RUNNING)
    //;
    $SBConfig
        // Database
        ->setString(SeablastConstant::SB_PHINX_ENVIRONMENT, 'testing')
    ;
};

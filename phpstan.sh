#!/bin/bash
# Run PHPStan Pro to test the repo

# output string param1 with color highlighting
section_title() {
    # color constants
    #HIGHLIGHT='\033[1;36m' # light cyan
    #NC='\033[0m' # No Color
    printf "\033[1;36m%s\033[0m\n" "$1"
}

warning() {
    # color constants
    #WARNING='\033[0;31m' # red
    #NC='\033[0m' # No Color
    printf "\033[0;31m%s\033[0m\n" "$1"
}

echo "To work on low performing environments, the script accepts number of seconds as parameter to be used as a waiting time between steps."
paramSleepSec=0
[ "$1" ] && [ "$1" -ge 0 ] && paramSleepSec=$1

section_title "- install Composer folder"
composer install -a --prefer-dist --no-progress
sleep "$paramSleepSec"s

#TODO once PHPUnit>=7 will only be used
#composer require --dev phpstan/phpstan-phpunit --prefer-dist --no-progress

section_title "- require --dev phpstan"
composer require --dev phpstan/phpstan-webmozart-assert --prefer-dist --no-progress --with-all-dependencies
sleep "$paramSleepSec"s

[ ! -f "phpunit.xml" ] && warning "NO phpunit.xml CONFIGURATION"
if [[ -f "phpunit.xml" ]]; then
    section_title "- phpunit"
    vendor/bin/phpunit
    sleep "$paramSleepSec"s
fi

section_title "- phpstan"
vendor/bin/phpstan.phar --configuration=conf/phpstan.webmozart-assert.neon analyse . --memory-limit 300M --pro

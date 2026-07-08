#!/bin/bash

echo "Running PHPCBF to auto-fix violations..."
vendor/bin/phpcbf --standard=WordPress --extensions=php includes/ admin/ split-payment-plugin.php

echo "Checking remaining violations..."
vendor/bin/phpcs --standard=phpcs.xml.dist includes/ admin/ split-payment-plugin.php

echo "Done!"

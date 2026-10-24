# FPDI + FPDF — one-time local setup

`includes/pdf-prebuilt.php` needs FPDI (imports pages from an existing PDF)
and FPDF (draws the header/footer on top of them). This repo has no
Composer/build step, and this environment can't fetch third-party library
source reliably — so getting the actual library files into this folder is
a one-time manual step, done once on your own machine:

```sh
mkdir -p /tmp/fpdi-fetch && cd /tmp/fpdi-fetch
composer require setasign/fpdi setasign/fpdf
```

Then copy the **entire** resulting `vendor/` directory's contents into
this folder, so you end up with:

```
includes/vendor/autoload.php
includes/vendor/composer/...
includes/vendor/setasign/fpdf/...
includes/vendor/setasign/fpdi/...
```

Composer never needs to run on the actual server — this is purely a local
step to fetch plain PHP files, which then get uploaded/committed like any
other file in this app. `includes/pdf-prebuilt.php` just does
`require_once __DIR__ . '/vendor/autoload.php';` and uses
`setasign\Fpdi\Fpdi` from there — nothing else needs to change once this
folder is populated.

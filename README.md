# omnibill/chorus-pro

**Chorus Pro**, the French public sector's invoicing portal, for
[glitchr/omnibill](https://github.com/glitchr-studio/omnibill), through PISTE: a supplier's
invoices to public entities (B2G), deposited as flows (`deposer/flux`) and followed
(`rechercher/fournisseur`).

```php
use Omnibill\ChorusPro\ChorusProGatewayFactory;

$public = (new ChorusProGatewayFactory($httpClient))->create([
    'client_id' => $pisteClientId, 'client_secret' => $pisteSecret,     // a PISTE application
    'login' => 'TECH_1_atelier@cpro.fr', 'password' => $password,        // a Chorus Pro technical account
    'sandbox' => true,
]);

$flow = $public->submit($invoice);               // numeroFluxDepot: the flow's reference
$public->fetchStatus($flow)->status;             // MISE_A_DISPOSITION read as MADE_AVAILABLE...
```

Written from Chorus Pro's "Factures" OpenAPI (as published on api.gouv.fr) and its annex on the API
services. **Not verified in real: no PISTE account.**

[Documentation](docs/index.md): the options, the routes, the statuses' reading, what was verified.

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.

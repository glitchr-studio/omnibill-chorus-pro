---
title: omnibill/chorus-pro
order: 1
---

# omnibill/chorus-pro

## Installation

```sh
composer require omnibill/chorus-pro
```

PHP 8.2 or later, `glitchr/omnibill` and `symfony/http-client`. A PISTE application subscribed to
the Chorus Pro APIs, and a Chorus Pro technical account attached to the supplier's structure (see
Chorus Pro's guides "Se raccorder à Chorus Pro" and "création d'un compte technique").

## The sources

Read on 2026-10-08:

- Chorus Pro's "Factures" OpenAPI, as data.gouv.fr points to it (`api_gouv_swaggers`,
  `api-chorus-pro.json`): the routes `/v1/deposer/flux`, `/v1/rechercher/fournisseur`,
  `/v1/consulter/historique`, the `cpro-account` header, the bodies, `statutCourantCode`;
- the annex on the API services of Chorus Pro's external specifications (V5.00,
  communaute.chorus-pro.gouv.fr): `deposerFluxFacture` (its syntaxes, 10 MB a file, 30 compressed,
  its errors), `consulterCR`, `consulterCRDetaille`;
- the DGFiP's external specifications v3.2, file "Chorus Pro" v1.1: PISTE, the API channel;
- PISTE's addresses: `https://oauth.piste.gouv.fr/api/oauth/token`, `https://api.piste.gouv.fr`,
  and their sandboxes.

## Options

| Option | Default | |
|---|---|---|
| `client_id`, `client_secret` | required | the PISTE application's (OAuth 2.0 client credentials, scope `openid`) |
| `login`, `password` | required | the technical account: sent as `cpro-account: base64(login:password)` |
| `sandbox` | `false` | PISTE's sandbox (`sandbox-oauth.piste.gouv.fr`, `sandbox-api.piste.gouv.fr`), Chorus Pro's qualification |
| `user_id` | | `idUtilisateurCourant`, when the account asks for it |
| `syntaxes` | `{Factur-X: IN_DP_E2_CII_FACTURX, UBL: IN_DP_E1_UBL_INVOICE, CII: IN_DP_E1_CII_16B}` | the `syntaxeFlux` of each syntax; `IN_DP_E2_CII_MIN_16B`, `IN_DP_E2_UBL_INVOICE_MIN` for a minimal flow with a PDF |
| `api_url`, `oauth_url` | PISTE's | |

## Operations

| | Route | |
|---|---|---|
| `submit()` | `POST /cpro/factures/v1/deposer/flux`: `fichierFlux` (base64), `nomFichier`, `syntaxeFlux`, `avecSignature: false` | `numeroFluxDepot` names the flow, `PENDING` |
| `fetchStatus()` | `POST /cpro/factures/v1/rechercher/fournisseur`: `numeroFacture` | the invoice Chorus Pro integrated - the one from this flow when several bear the number -, its `statut`: `ACCEPTED`, and the status below; not found yet: still `PENDING` |

Every answer carries `codeRetour` (0: done) and `libelle`; another code is a `ProviderException`
with it (`20001`, `GDP_MSG_11.022`: the file is too large). HTTP 401 or 403 is an
`InvalidKeyException`: PISTE or the technical account refused.

Chorus Pro's statuses read as the reform's - this package's reading, the code always kept in
`StatusChange::$code`:

| Chorus Pro | Reform |
|---|---|
| `DEPOSEE` | `DEPOSITED` |
| `EN_COURS_ACHEMINEMENT` | `ISSUED` |
| `MISE_A_DISPOSITION` | `MADE_AVAILABLE` |
| `PRISE_EN_COMPTE_DESTINATAIRE` | `TAKEN_OVER` |
| `SERVICE_FAIT`, `MANDATEE` | `APPROVED` |
| `SUSPENDUE`, `A_COMPLETER` | `SUSPENDED` |
| `COMPLETEE` | `COMPLETED` |
| `REJETEE` | `REFUSED` |
| `MISE_EN_PAIEMENT` | `PAYMENT_SENT` |

The validation circuits (`A_VALIDER_1`, `VALIDEE_2`...) and Chorus Pro's own states have no
counterpart: the flow keeps its last status, the code is in `data`.

Not done: receiving (the public entities' side), the directory of structures (another API),
e-reporting, callbacks; the deposit report (`consulterCR`) - its route on PISTE was not found in
the documentation read.

## Verified, and not

| | |
|---|---|
| Against PISTE and Chorus Pro | **not verified in real: no PISTE application, no technical account.** The answers in `Tests/Fixtures` are written from the OpenAPI and the annex |
| The token, the `cpro-account` header, the bodies, the syntaxes | by the tests |
| The OpenAPI's host | it names `api.aife.economie.gouv.fr`; PISTE's current address, `api.piste.gouv.fr`, is the default here - `api_url` changes it |

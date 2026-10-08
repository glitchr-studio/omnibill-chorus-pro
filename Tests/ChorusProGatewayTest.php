<?php

namespace Omnibill\ChorusPro\Tests;

use Omnibill\ChorusPro\ChorusProGatewayFactory;
use Omnibill\ChorusPro\Statuses;
use Omnibill\Exception\InvalidConfigException;
use Omnibill\Exception\InvalidKeyException;
use Omnibill\Exception\ProviderException;
use Omnibill\GatewayInterface;
use Omnibill\Model\Flow;
use Omnibill\Model\FlowState;
use Omnibill\Model\Invoice;
use Omnibill\Model\LifecycleStatus;
use Omnibill\Model\Syntax;
use Omnibill\Request\Lookup;
use Omnibill\Request\Receive;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers written from Chorus Pro's "Factures" OpenAPI (as published on
 * api.gouv.fr) and its annex on the API services (V5.00): no PISTE account
 * was used.
 */
final class ChorusProGatewayTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $calls = [];

    /** @param list<string|MockResponse> $answers */
    private function gateway(array $answers, array $options = []): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            $this->calls[] = [$method, $url, $options];
            if (str_contains($url, '/oauth/token')) {
                return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/token.json'));
            }
            $answer = array_shift($answers) ?? throw new \LogicException('No answer left.');
            if ($answer instanceof MockResponse) {
                return $answer;
            }

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$answer.'.json'), ['response_headers' => ['content-type' => 'application/json']]);
        });

        return (new ChorusProGatewayFactory($http))->create($options + ['client_id' => 'piste-app', 'client_secret' => 'piste-secret', 'login' => 'TECH_1_atelier@cpro.fr', 'password' => 'p4ss']);
    }

    private static function invoice(): Invoice
    {
        return new Invoice('%PDF-1.7 Factur-X', 'F-2026-042.pdf', Syntax::FACTURX, 'F-2026-042');
    }

    public function testAnInvoiceIsDepositedAsAFlowWithTheTechnicalAccount(): void
    {
        $flow = $this->gateway(['deposer-flux'])->submit(self::invoice());

        self::assertSame(['CPP0021100000000000000023', FlowState::PENDING, 'F-2026-042', '2026-10-08'], [$flow->reference, $flow->state, $flow->invoiceNumber, $flow->submittedAt?->format('Y-m-d')]);
        [$token, $deposit] = $this->calls;
        self::assertSame('https://oauth.piste.gouv.fr/api/oauth/token', $token[1]);
        parse_str((string) $token[2]['body'], $grant);
        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => 'piste-app', 'client_secret' => 'piste-secret', 'scope' => 'openid'], $grant);
        self::assertSame(['POST', 'https://api.piste.gouv.fr/cpro/factures/v1/deposer/flux'], [$deposit[0], $deposit[1]]);
        $headers = implode("\n", $deposit[2]['headers']);
        self::assertStringContainsString('Authorization: Bearer Ex4mpleP1steT0ken', $headers);
        self::assertStringContainsString('cpro-account: '.base64_encode('TECH_1_atelier@cpro.fr:p4ss'), $headers);
        self::assertSame(['fichierFlux' => base64_encode('%PDF-1.7 Factur-X'), 'nomFichier' => 'F-2026-042.pdf', 'syntaxeFlux' => 'IN_DP_E2_CII_FACTURX', 'avecSignature' => false], json_decode((string) $deposit[2]['body'], true));
    }

    public function testEachSyntaxHasItsSyntaxeFluxAndTheSandboxItsAddresses(): void
    {
        $this->gateway(['deposer-flux', 'deposer-flux'], ['sandbox' => true, 'user_id' => 42])->submit(new Invoice('<Invoice/>', 'F-1.xml', Syntax::UBL, 'F-1'));
        self::assertSame('https://sandbox-oauth.piste.gouv.fr/api/oauth/token', $this->calls[0][1]);
        self::assertSame('https://sandbox-api.piste.gouv.fr/cpro/factures/v1/deposer/flux', $this->calls[1][1]);
        self::assertSame(['idUtilisateurCourant' => 42, 'syntaxeFlux' => 'IN_DP_E1_UBL_INVOICE'], array_intersect_key(json_decode((string) $this->calls[1][2]['body'], true), ['idUtilisateurCourant' => 1, 'syntaxeFlux' => 1]));

        $this->calls = [];
        $this->gateway(['deposer-flux'], ['syntaxes' => ['CII' => 'IN_DP_E2_CII_MIN_16B']])->submit(new Invoice('<x/>', 'F-2.xml', Syntax::CII));
        self::assertSame('IN_DP_E2_CII_MIN_16B', json_decode((string) $this->calls[1][2]['body'], true)['syntaxeFlux']);

        $this->expectException(InvalidConfigException::class);
        $this->gateway([])->submit(new Invoice('<x/>', 'CDV.xml', Syntax::CDAR));
    }

    public function testTheInvoicesStatusIsChorusProsReadAsTheReforms(): void
    {
        $gateway = $this->gateway(['deposer-flux', 'rechercher-fournisseur-vide', 'rechercher-fournisseur']);
        $flow = $gateway->submit(self::invoice());

        $still = $gateway->fetchStatus($flow);
        self::assertSame([FlowState::PENDING, null], [$still->state, $still->status], 'not integrated yet');
        $found = $gateway->fetchStatus($flow);
        self::assertSame([FlowState::ACCEPTED, LifecycleStatus::MADE_AVAILABLE, 'MISE_A_DISPOSITION'], [$found->state, $found->status, $found->history[0]->code]);
        self::assertSame(['numeroFacture' => 'F-2026-042'], json_decode((string) $this->calls[array_key_last($this->calls)][2]['body'], true));
        self::assertSame(4321098, $found->data['facture']['identifiantFactureCPP']);

        self::assertSame(LifecycleStatus::REFUSED, Statuses::of('REJETEE'));
        self::assertNull(Statuses::of('A_VALIDER_1'), 'a validation circuit is not a status of the reform');

        $this->expectException(InvalidConfigException::class);
        $gateway->fetchStatus(new Flow('CPP0021100000000000000099'));
    }

    public function testChorusProsErrorsAndRefusals(): void
    {
        try {
            $this->gateway(['deposer-flux-trop-gros'])->submit(self::invoice());
            self::fail();
        } catch (ProviderException $e) {
            self::assertSame(['[chorus-pro] /v1/deposer/flux answered 20001: GDP_MSG_11.022', '20001'], [$e->getMessage(), $e->providerCode]);
        }
        $this->expectException(InvalidKeyException::class);
        $this->gateway([new MockResponse('{"httpCode":"403","httpMessage":"Forbidden","moreInformation":"cpro-account"}', ['http_code' => 403])])->submit(self::invoice());
    }

    public function testWhatChorusProDoesNotGiveASupplier(): void
    {
        $gateway = $this->gateway([]);

        self::assertFalse($gateway->supports(Receive::class));
        self::assertFalse($gateway->supports(Lookup::class));
        self::assertTrue($gateway->capabilities()->publicSector);
        self::assertFalse($gateway->capabilities()->approved);
    }
}

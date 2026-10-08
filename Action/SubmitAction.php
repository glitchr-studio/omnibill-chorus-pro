<?php

namespace Omnibill\ChorusPro\Action;

use Omnibill\Action\ActionInterface;
use Omnibill\Action\ApiAwareInterface;
use Omnibill\Action\ApiAwareTrait;
use Omnibill\ChorusPro\Api;
use Omnibill\Exception\InvalidConfigException;
use Omnibill\Model\Direction;
use Omnibill\Model\Flow;
use Omnibill\Model\FlowKind;
use Omnibill\Model\FlowState;
use Omnibill\Model\Syntax;
use Omnibill\Request\Request;
use Omnibill\Request\Submit;

/**
 * POST /v1/deposer/flux: the file in base64, its name, its syntax in Chorus
 * Pro's words (syntaxeFlux). The answer's numeroFluxDepot names the flow.
 */
final class SubmitAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    /** @param array<string, string> $syntaxes Chorus Pro's syntaxeFlux for each Syntax */
    public function __construct(private readonly array $syntaxes)
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Submit;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Submit);
        $invoice = $request->invoice;
        $syntax = $this->syntaxes[$invoice->syntax->value] ?? throw new InvalidConfigException(\sprintf('Chorus Pro takes no %s file through this gateway.', $invoice->syntax->value));
        $data = $this->api->call('/v1/deposer/flux', [
            'fichierFlux' => base64_encode($invoice->content),
            'nomFichier' => $invoice->filename,
            'syntaxeFlux' => $syntax,
            'avecSignature' => false,
        ]);
        $deposited = isset($data['dateDepot']) ? (new \DateTimeImmutable((string) $data['dateDepot'])) : new \DateTimeImmutable();
        $request->setResult(new Flow(
            reference: (string) ($data['numeroFluxDepot'] ?? ''),
            kind: FlowKind::INVOICE,
            direction: Direction::OUT,
            state: FlowState::PENDING,
            trackingId: $request->trackingId,
            invoiceNumber: $invoice->number,
            submittedAt: $deposited,
            updatedAt: $deposited,
            name: $invoice->filename,
            data: $data,
        ));
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [Syntax::FACTURX->value => 'IN_DP_E2_CII_FACTURX', Syntax::UBL->value => 'IN_DP_E1_UBL_INVOICE', Syntax::CII->value => 'IN_DP_E1_CII_16B'];
    }
}

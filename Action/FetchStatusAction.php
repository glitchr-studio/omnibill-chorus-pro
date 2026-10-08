<?php

namespace Omnibill\ChorusPro\Action;

use Omnibill\Action\ActionInterface;
use Omnibill\Action\ApiAwareInterface;
use Omnibill\Action\ApiAwareTrait;
use Omnibill\ChorusPro\Api;
use Omnibill\ChorusPro\Statuses;
use Omnibill\Exception\InvalidConfigException;
use Omnibill\Model\FlowState;
use Omnibill\Model\StatusChange;
use Omnibill\Request\FetchStatus;
use Omnibill\Request\Request;

/**
 * POST /v1/rechercher/fournisseur on the invoice's number: the invoice as
 * Chorus Pro integrated it - from the flow it was deposited in, when
 * several bear that number - and its current status (statut). Not found
 * yet: the flow is still being processed, PENDING.
 */
final class FetchStatusAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchStatus;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchStatus);
        $flow = $request->flow;
        $number = $flow->invoiceNumber ?? throw new InvalidConfigException('Chorus Pro finds an invoice by its number: the Flow has none (give the Invoice a number when submitting it).');
        $found = $this->api->call('/v1/rechercher/fournisseur', ['numeroFacture' => $number])['listeFactures'] ?? [];
        $mine = array_values(array_filter((array) $found, static fn ($f) => \is_array($f) && ($f['numeroFluxDepot'] ?? null) === $flow->reference)) ?: array_values(array_filter((array) $found, 'is_array'));
        if (!$mine) {
            $request->setResult($flow);

            return;
        }
        $invoice = $mine[0];
        $code = isset($invoice['statut']) ? (string) $invoice['statut'] : null;
        $status = Statuses::of($code);
        $at = isset($invoice['dateHeureEtatCourant']) ? (new \DateTimeImmutable((string) $invoice['dateHeureEtatCourant'])) : null;
        $history = $flow->history;
        if (null !== $status && (!$history || $history[array_key_last($history)]->code !== $code)) {
            $history[] = new StatusChange($status, $number, $at, $invoice['commentaireEtatCourant'] ?? null, code: $code);
        }
        $request->setResult($flow->with(state: FlowState::ACCEPTED, status: $status ?? $flow->status, history: $history, updatedAt: $at, data: ['facture' => $invoice] + $flow->data));
    }
}

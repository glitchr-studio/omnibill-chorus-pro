<?php

namespace Omnibill\ChorusPro;

use Omnibill\Model\LifecycleStatus;

/**
 * Chorus Pro's statuses of an invoice (statutCourantCode) as the reform's
 * lifecycle statuses - this package's reading of them, where one matches;
 * the code itself is always kept. Chorus Pro's validation circuits
 * (A_VALIDER_1, VALIDEE_2...) and its own technical states have none.
 */
final class Statuses
{
    public const MAP = [
        'DEPOSEE' => LifecycleStatus::DEPOSITED,
        'EN_COURS_ACHEMINEMENT' => LifecycleStatus::ISSUED,
        'MISE_A_DISPOSITION' => LifecycleStatus::MADE_AVAILABLE,
        'PRISE_EN_COMPTE_DESTINATAIRE' => LifecycleStatus::TAKEN_OVER,
        'SERVICE_FAIT' => LifecycleStatus::APPROVED,
        'MANDATEE' => LifecycleStatus::APPROVED,
        'SUSPENDUE' => LifecycleStatus::SUSPENDED,
        'A_COMPLETER' => LifecycleStatus::SUSPENDED,
        'COMPLETEE' => LifecycleStatus::COMPLETED,
        'REJETEE' => LifecycleStatus::REFUSED,
        'MISE_EN_PAIEMENT' => LifecycleStatus::PAYMENT_SENT,
    ];

    public static function of(?string $code): ?LifecycleStatus
    {
        return null === $code ? null : (self::MAP[$code] ?? null);
    }
}

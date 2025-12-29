<?php

declare(strict_types=1);

namespace App\Services\Trading;

use InvalidArgumentException;

/**
 * Подсчет количества денег для использования в order в зависимости от баланса
 */
final class RiskManager
{
    private const int DEFAULT_DECIMALS_FOR_QTY = 8;

    // todo потом эту константу надо будет вынести в админку, чтобы там можно было менять этот процент
    public const float RISK_PERCENT_FOR_LOST = 0.03;

    // todo потом эту константу надо будет вынести в админку, чтобы там можно было менять этот процент
    public const float PERCENT_FOR_UNDEFINED_TAKE_PROFIT = 0.15;

    // todo потом эту константу надо будет вынести в админку, чтобы там можно было менять этот процент
    public const float PERCENT_FOR_UNDEFINED_STOP_LOSS = 0.05;

    /**
     * Рассчитать сумму (USDT) для использования в сделке по проценту депозита.
     *
     * @param float $currentBalance баланс (USDT)
     * @param float $riskPercent доля в виде 0.03 = 3%
     * @return float сумма в USDT
     */
    public function balanceToUseFromPercent(float $currentBalance, float $riskPercent): float
    {
        if ($currentBalance <= 0) {
            throw new InvalidArgumentException('Account balance must be > 0.');
        }

        return $currentBalance * $riskPercent;
    }

    /**
     * Рассчитать qty (количество базового актива) по риску (для linear / USDT-margined).
     *
     * Формула:
     *   qty = riskMoney / stopLossDistance
     *
     * Где stopLossDistance = abs(entryPrice - stopLossPrice).
     *
     * @param float $riskMoney сумма в USDT, которую готов потерять
     * @param float $entryPrice цена входа (USDT)
     * @param float $stopLossPrice цена стопа (USDT)
     * @return float qty (не округлённый)
     */
    public function calculateQtyFromRiskLinear(float $riskMoney, float $entryPrice, float $stopLossPrice): float
    {
        if ($riskMoney <= 0) {
            throw new InvalidArgumentException('Risk money must be > 0.');
        }

        if ($entryPrice <= 0 || $stopLossPrice <= 0) {
            throw new InvalidArgumentException('Prices must be > 0.');
        }

        $slDistance = abs($entryPrice - $stopLossPrice);

        if ($slDistance == 0.0) {
            throw new InvalidArgumentException('Stop loss distance can not be zero.');
        }

        // Количество базового актива
        return $riskMoney / $slDistance;
    }

    /**
     * Привести qty к шагу количества (qtyStep).
     *
     * @param float $qty
     * @param float $qtyStep
     * @return float
     */
    public function applyQtyStep(float $qty, float $qtyStep): float
    {
        if ($qtyStep <= 0) {
            throw new InvalidArgumentException('qtyStep must be > 0.');
        }

        // floor -> не рисковать превышением минимально допустимого шага
        $rounded = floor($qty / $qtyStep) * $qtyStep;

        // избавиться от дробных артефактов (например 0.999999999)
        return $this->trimFloat($rounded);
    }

    /**
     * Учитывая пределы Bybit, привести qty к минимальным требованиям:
     * - убедиться qty >= minQty
     * - убедиться qty * entryPrice >= minOrderValue (если задано)
     * Возвращает скорректированный qty или 0 если не получится.
     *
     * @param float $qty
     * @param float $qtyStep
     * @param float $minQty
     * @param float $entryPrice
     * @return float скорректированное qty (или 0 если невалидно)
     */
    public function enforceLimits(
        float $qty,
        float $qtyStep,
        float $minQty,
        float $entryPrice
    ): float {
        $qty = $this->applyQtyStep($qty, $qtyStep);

        if ($qty < $minQty) {
            return 0.0;
        }

//        if ($minOrderValue !== null) {
//            $orderValue = $qty * $entryPrice;
//            if ($orderValue < $minOrderValue) {
//                // попробовать увеличить qty до minOrderValue (с учётом шага)
//                $neededQty = ceil(($minOrderValue / $entryPrice) / $qtyStep) * $qtyStep;
//                if ($neededQty < $minQty) {
//                    $neededQty = $minQty;
//                }
//                // если after rounding still > original qty, вернуть neededQty
//                return $this->applyQtyStep($neededQty, $qtyStep);
//            }
//        }

        return $qty;
    }

    /**
     * Рассчитать требуемую маржу (USDT) для linear контрактов.
     *
     * requiredMargin = (qty * entryPrice) / leverage
     *
     * @param  float  $qty
     * @param  float  $entryPrice
     * @param  int  $leverage
     * @return float
     */
    public function requiredMargin(float $qty, float $entryPrice, int $leverage): float
    {
        if ($leverage <= 0) {
            throw new InvalidArgumentException('Leverage must be > 0.');
        }

        return ($qty * $entryPrice) / $leverage;
    }

    /**
     * Если маржа больше доступной, уменьшаем qty пропорционально, чтобы requiredMargin <= availableMargin.
     *
     * Возвращает новый qty (с учётом qtyStep) или 0 если не возможно.
     *
     * @param float $qty
     * @param float $entryPrice
     * @param int $leverage
     * @param float $availableMargin
     * @param float $qtyStep
     * @return float
     */
    public function fitQtyByMargin(
        float $qty,
        float $entryPrice,
        int $leverage,
        float $availableMargin,
        float $qtyStep
    ): float {
        $required = $this->requiredMargin($qty, $entryPrice, $leverage);

        if ($required <= $availableMargin) {
            return $this->applyQtyStep($qty, $qtyStep);
        }

        // уменьшаем qty пропорционально
        $ratio = $availableMargin / $required;
        $newQty = $qty * $ratio;

        return $this->applyQtyStep($newQty, $qtyStep);
    }

    /**
     * Разбить qty на части для целей TP.
     * Если targetsWeights передан — использовать его (сумма = 1.0). Иначе -
     * применить веса по количеству: 2 => [0.6,0.4], 3 => [0.5,0.3,0.2], иначе равномерно.
     *
     * Возвращает массив qty по каждому tp (в порядке targets).
     *
     * @param  float  $totalQty
     * @param  int  $targetsCount
     * @param  float  $qtyStep
     * @param  array|null  $targetsWeights
     * @return float[]
     */
    public function splitTargetsQty(float $totalQty, int $targetsCount, float $qtyStep, ?array $targetsWeights = null): array
    {
        if ($targetsCount <= 0) {
            return [];
        }

        if ($targetsWeights !== null) {
            $sum = array_sum($targetsWeights);
            if ($sum <= 0.0) {
                throw new InvalidArgumentException('Invalid targetsWeights sum.');
            }
            // нормализуем
            $weights = array_map(fn($w) => $w / $sum, $targetsWeights);
        } else {
            $weights = match ($targetsCount) {
                1 => [1.0],
                2 => [0.6, 0.4],
                3 => [0.5, 0.3, 0.2],
                4 => [0.5, 0.2, 0.2, 0.1],
                5 => [0.4, 0.2, 0.2, 0.1, 0.1],
                default => array_fill(0, $targetsCount, 1 / $targetsCount),
            };
        }

        $result = [];
        foreach ($weights as $w) {
            $result[] = $this->applyQtyStep($totalQty * $w, $qtyStep);
        }

        return $result;
    }

    /**
     * Вспомогательный trim float
     */
    private function trimFloat(float $v, int $precision = self::DEFAULT_DECIMALS_FOR_QTY): float
    {
        return (float) number_format($v, $precision, '.', '');
    }
}

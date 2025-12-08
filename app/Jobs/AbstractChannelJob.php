<?php

declare(strict_types=1);


namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

class AbstractChannelJob implements ShouldQueue
{
    use Queueable;

    public function __construct(protected readonly array $data)
    {
    }

    /**
     * По этим словам буду определять, это сообщение с нужной информацией или нет
     */
    private const array NECESSARY_WORDS_IN_MESSAGE = [
        'тейк',
        'take',
        'профит',
        'profit',
        'тп',
        'tp',
        'стоп',
        'stop',
        'лос',
        'los',
        'сл',
        'sl',
        'лимит',
        'limit',
        'рынок',
        'market',
    ];

    /**
     * Данные для передачи данных для установления Order, которые не могут быть пустыми
     */
    private const array NECESSARY_KEYS_FOR_SET_ORDER = [
        'symbol' => true,
        'side' => true,
        'entry' => true,
        'targets' => true,
        'stopLoss' => true,
    ];

    protected string $defaultExchange;

    /**
     * Приводит сообщение в нижний регистр и удаляет все символы, которые могут мешать найти нужные слова
     * Возвращает true, если хоть одно нужное слово есть в сообщении
     */
    protected function checkIfItNecessaryMessage(string $message): bool
    {
        $message = Str::lower(Str::remove(['.', '-', '/'], $message));

        return Str::contains($message, self::NECESSARY_WORDS_IN_MESSAGE);
    }

    /**
     * Проверяет, что все необходимые ключи имеют непустые значения
     */
    protected function checkIfAllNecessaryDataPresent(array $data): bool
    {
        return count(
                array_filter(
                    array_intersect_key($data, self::NECESSARY_KEYS_FOR_SET_ORDER),
                ),
            ) === count(self::NECESSARY_KEYS_FOR_SET_ORDER);
    }

    /**
     * Запоминает, при необходимости, биржу по умолчанию и возвращает ее
     */
    protected function getDefaultExchange(): string
    {
        if (!isset($this->defaultExchange)) {
            $this->defaultExchange = config('exchanges.default_exchange');
        }

        return $this->defaultExchange;
    }
}

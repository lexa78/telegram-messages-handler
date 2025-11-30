<?php

declare(strict_types=1);

namespace App\Patterns\Factories;

/**
 * Возвращает объект обработчика сообщений в зависимости от имени класса
 */
class JobFactory
{
    public static function make(string $className, array $data)
    {
        // Возвращаем экземпляр Job с данными
        return new $className($data);
    }
}

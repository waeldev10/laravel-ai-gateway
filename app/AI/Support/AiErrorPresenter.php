<?php

namespace App\AI\Support;

use App\AI\Exceptions\AiAuthenticationException;
use App\AI\Exceptions\AiAuthorizationException;
use App\AI\Exceptions\AiConnectionException;
use App\AI\Exceptions\AiContentPolicyException;
use App\AI\Exceptions\AiContextLimitException;
use App\AI\Exceptions\AiException;
use App\AI\Exceptions\AiInsufficientBalanceException;
use App\AI\Exceptions\AiInvalidRequestException;
use App\AI\Exceptions\AiInvalidResponseException;
use App\AI\Exceptions\AiModelNotFoundException;
use App\AI\Exceptions\AiProviderException;
use App\AI\Exceptions\AiRateLimitException;
use App\AI\Exceptions\AiTimeoutException;
use App\AI\Exceptions\AiUnavailableException;
use App\AI\Exceptions\AiUsageLimitException;

/**
 * Centralized application-level mapping from technical AI exceptions to
 * safe, actionable Arabic user-facing messages.
 *
 * Backend exceptions only classify failures (provider, status, request
 * id, sanitized diagnostic); they never format user text themselves —
 * every `userMessage()` delegates here, so this is the single place
 * where AI failure copy lives. Nothing returned here ever contains keys,
 * headers, payloads, prompts, or raw provider errors.
 */
class AiErrorPresenter
{
    public static function messageFor(AiException $failure): string
    {
        if ($failure instanceof AiUsageLimitException) {
            return self::usageLimitMessage($failure);
        }

        if ($failure instanceof AiRateLimitException) {
            return self::providerRateLimitMessage($failure);
        }

        return match ($failure::class) {
            AiAuthenticationException::class => 'مفتاح API الخاص بمزود الذكاء الاصطناعي غير صالح أو غير صحيح.',
            AiAuthorizationException::class => 'ليس لديك صلاحية لاستخدام هذا النموذج أو هذا المورد.',
            AiInsufficientBalanceException::class => 'رصيد مزود الذكاء الاصطناعي غير كافٍ لتنفيذ الطلب.',
            AiTimeoutException::class => 'استغرق الاتصال بمزود الذكاء الاصطناعي وقتًا أطول من المتوقع. يرجى المحاولة مرة أخرى.',
            AiConnectionException::class => 'تعذر الاتصال بمزود الذكاء الاصطناعي. تحقق من الاتصال ثم حاول مرة أخرى.',
            AiUnavailableException::class => 'مزود الذكاء الاصطناعي غير متاح حاليًا. يرجى المحاولة لاحقًا.',
            AiInvalidResponseException::class => 'وصلت استجابة غير صالحة من مزود الذكاء الاصطناعي. يرجى المحاولة مرة أخرى.',
            AiInvalidRequestException::class => 'الطلب المرسل إلى مزود الذكاء الاصطناعي غير صالح.',
            AiModelNotFoundException::class => 'النموذج المحدد غير متاح لدى مزود الذكاء الاصطناعي. تحقق من اسم النموذج وإعداداته.',
            AiContextLimitException::class => 'المحادثة طويلة جدًا ولا يمكن إرسالها إلى النموذج الحالي. حاول تقليل محتوى المحادثة أو بدء محادثة جديدة.',
            AiContentPolicyException::class => 'لم يتمكن مزود الذكاء الاصطناعي من معالجة هذا الطلب بسبب سياسات المحتوى الخاصة به.',
            AiProviderException::class => 'حدث خطأ أثناء الاتصال بمزود الذكاء الاصطناعي. يرجى المحاولة مرة أخرى.',
            default => 'حدث خطأ أثناء الاتصال بمزود الذكاء الاصطناعي. يرجى المحاولة مرة أخرى.',
        };
    }

    /**
     * Application-level usage budget (AiUsageService) — deliberately
     * worded differently from the provider rate-limit message below so
     * the two systems are never confused.
     */
    private static function usageLimitMessage(AiUsageLimitException $failure): string
    {
        if ($failure->retryAfterSeconds() !== null && $failure->retryAfterSeconds() > 0) {
            return 'لقد وصلت إلى حد الاستخدام المسموح. حاول مرة أخرى بعد قليل.';
        }

        return 'لقد وصلت إلى حد الاستخدام المسموح. حاول مرة أخرى لاحقاً.';
    }

    /**
     * Upstream provider rate limit (HTTP 429). When the provider tells
     * us how long to wait, the message says so.
     */
    private static function providerRateLimitMessage(AiRateLimitException $failure): string
    {
        $base = 'تم الوصول إلى الحد المسموح للطلبات. يرجى الانتظار ثم المحاولة مرة أخرى.';
        $after = $failure->retryAfterSeconds();

        if ($after === null || $after <= 0) {
            return $base;
        }

        return $base.' (حاول مرة أخرى بعد '.self::arabicDuration($after).'.)';
    }

    private static function arabicDuration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;
        $parts = [];

        if ($minutes > 0) {
            $parts[] = $minutes.' '.self::arabicUnit($minutes, 'دقيقة', 'دقيقتان', 'دقائق');
        }

        if ($rest > 0) {
            $parts[] = $rest.' '.self::arabicUnit($rest, 'ثانية', 'ثانيتان', 'ثوانٍ');
        }

        return implode(' و', $parts);
    }

    private static function arabicUnit(int $count, string $one, string $two, string $few): string
    {
        if ($count === 1) {
            return $one;
        }

        if ($count === 2) {
            return $two;
        }

        return $count <= 10 ? $few : $one;
    }
}

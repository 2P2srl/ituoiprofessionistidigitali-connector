<?php

declare(strict_types=1);

namespace ITuoiProfessionistiDigitali\Connector\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use ITuoiProfessionistiDigitali\Connector\ConnectorConfig;
use ITuoiProfessionistiDigitali\Connector\Contract;
use ITuoiProfessionistiDigitali\Connector\Exceptions\InvalidSignatureException;
use ITuoiProfessionistiDigitali\Connector\Signature\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses with 401, before anything reads the envelope, a webhook not signed by the platform (rule W4).
 *
 * @internal
 */
final readonly class VerifyPlatformSignature
{
    public function __construct(private ConnectorConfig $config) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try
        {
            WebhookSignature::verify(
                body: $request->getContent(),
                timestamp: $request->header(Contract::HEADER_TIMESTAMP),
                signatures: $request->header(Contract::HEADER_SIGNATURE),
                secrets: $this->config->signingSecret === null ? [] : [$this->config->signingSecret],
                now: CarbonImmutable::now()->getTimestamp(),
            );
        }
        catch (InvalidSignatureException $exception)
        {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return $next($request);
    }
}

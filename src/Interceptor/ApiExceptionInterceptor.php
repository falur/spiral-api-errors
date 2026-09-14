<?php

declare (strict_types=1);

namespace GianTiaga\SpiralApiErrors\Interceptor;

use Psr\Log\LoggerInterface;
use Spiral\Filters\Exception\ValidationException as FilterValidationException;
use Spiral\Interceptors\Context\CallContextInterface;
use Spiral\Interceptors\HandlerInterface;
use Spiral\Interceptors\InterceptorInterface;
use Spiral\Translator\TranslatorInterface;
use GianTiaga\SpiralApiErrors\Exception\TranslatableException;
use GianTiaga\SpiralOpenApi\Response\Enum\HttpStatus;
use GianTiaga\SpiralOpenApi\Response\ErrorResponse;

final readonly class ApiExceptionInterceptor implements InterceptorInterface
{
    private const int CLIENT_ERROR_MIN = 400;
    private const int CLIENT_ERROR_MAX = 499;
    /**
     * Серверные статусы, которые приложение объявляет само.
     *
     * Обычный 5xx наружу не выходит: его природа клиенту не сообщается. Исключение — 502: он
     * говорит не о поломке приложения, а о недоступности вышестоящей системы, за которую
     * приложение отвечать не может, и это ровно то, что клиенту нужно знать для повтора.
     *
     * @var list<HttpStatus>
     */
    private const array DECLARED_SERVER_STATUSES = [HttpStatus::BadGateway];
    public function __construct(private LoggerInterface $logger, private TranslatorInterface $translator) {}
    public function intercept(CallContextInterface $context, HandlerInterface $handler): mixed
    {
        try {
            return $handler->handle($context);
        } catch (\DomainException $exception) {
            return $this->domainExceptionResponse($exception);
        } catch (FilterValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return $this->unexpectedExceptionResponse($exception);
        }
    }
    private function domainExceptionResponse(\DomainException $exception): ErrorResponse
    {
        $status = $this->supportedStatus($exception);
        if ($status === null) {
            return $this->unexpectedExceptionResponse($exception);
        }
        if ($exception instanceof TranslatableException) {
            return $this->errorResponse(message: $this->translator->trans(id: $exception->translationKey(), parameters: $exception->translationParameters(), domain: $exception->translationDomain()), status: $status);
        }
        return $this->errorResponse(message: $exception->getMessage(), status: $status);
    }
    private function unexpectedExceptionResponse(\Throwable $exception): ErrorResponse
    {
        $this->logger->error(message: 'Непредвиденная ошибка API.', context: ['exceptionClass' => $exception::class, 'message' => $exception->getMessage(), 'file' => $exception->getFile(), 'line' => $exception->getLine()]);
        return $this->errorResponse(message: $this->translator->trans(id: 'gian_tiaga.spiral_api_errors.internal_server_error'), status: HttpStatus::InternalServerError);
    }
    private function supportedStatus(\DomainException $exception): HttpStatus|null
    {
        $status = HttpStatus::tryFrom($exception->getCode());
        if ($status === null) {
            return null;
        }
        if (\in_array(needle: $status, haystack: self::DECLARED_SERVER_STATUSES, strict: true)) {
            return $status;
        }
        if ($status->value < self::CLIENT_ERROR_MIN || $status->value > self::CLIENT_ERROR_MAX) {
            return null;
        }
        return $status;
    }
    private function errorResponse(string $message, HttpStatus $status): ErrorResponse
    {
        return (new ErrorResponse(message: $message, code: $status->value))->withStatus($status);
    }
}

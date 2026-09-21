<?php

declare (strict_types=1);

namespace GianTiaga\SpiralApiErrors\Filter;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Spiral\Filters\ErrorsRendererInterface;
use Spiral\Translator\TranslatorInterface;
use GianTiaga\SpiralOpenApi\Response\Enum\HttpStatus;
use GianTiaga\SpiralOpenApi\Response\ValidationErrorItemResponse;
use GianTiaga\SpiralOpenApi\Response\ValidationErrorResponse;

final readonly class ApiValidationErrorsRenderer implements ErrorsRendererInterface
{
    public function __construct(private LoggerInterface $logger, private TranslatorInterface $translator) {}
    /**
     * @param array<string, string> $errors
     */
    public function render(array $errors, mixed $context = null): ResponseInterface
    {
        $this->logger->debug(message: 'API вернул ошибки валидации Filter.', context: ['fields' => \array_keys($errors)]);
        return (new ValidationErrorResponse(message: $this->translator->trans(id: 'gian_tiaga.spiral_api_errors.validation_error'), code: HttpStatus::UnprocessableEntity->value, errors: $this->validationErrors($errors)))->withStatus(HttpStatus::UnprocessableEntity)->toResponse();
    }
    /**
     * @param array<string, string> $errors
     * @return list<ValidationErrorItemResponse>
     */
    private function validationErrors(array $errors): array
    {
        $validationErrors = [];
        foreach ($errors as $field => $message) {
            $validationErrors = [...$validationErrors, ...$this->fieldErrors(field: $field, message: $message)];
        }
        return $validationErrors;
    }
    /**
     * Ошибки одного поля приходят тремя видами: строкой от приведения значения, списком строк от
     * Symfony-валидатора и картой вложенного Filter, у которой ключ — имя поля внутри вложенного
     * объекта. Наружу все три уходят одной формой: адрес поля через точку и плоский список
     * сообщений. Иначе клиент разбирал бы `messages` то как список строк, то как объект, и одно
     * поле ответа значило бы две разные вещи.
     *
     * Вид значения называет его ключ: числовой ключ — это сообщение самого поля, строковый —
     * имя вложенного поля. Своё сообщение и ошибки внутри бывают у поля одновременно, и обе
     * стороны доходят до клиента.
     *
     * Контракт интерфейса — array<string, string>, и настоящую форму значения он не описывает:
     * вложенность у неё любой глубины, и типом её не выразить. Поэтому значение приходит сюда
     * нетипизированным и сужается явными проверками — это граница, а дальше неё ни `mixed`, ни
     * вложенный массив не уходят.
     *
     * @return list<ValidationErrorItemResponse>
     */
    private function fieldErrors(string $field, mixed $message): array
    {
        if (\is_string($message)) {
            return [new ValidationErrorItemResponse(field: $field, messages: [$message])];
        }
        if (!\is_array($message)) {
            return [];
        }
        $messages = [];
        $nested = [];
        foreach ($message as $key => $value) {
            if (\is_string($key)) {
                $nested = [...$nested, ...$this->fieldErrors(field: \sprintf('%s.%s', $field, $key), message: $value)];
                continue;
            }
            if (\is_string($value)) {
                $messages[] = $value;
            }
        }
        if ($nested === []) {
            return [new ValidationErrorItemResponse(field: $field, messages: $messages)];
        }
        return $messages === [] ? $nested : [new ValidationErrorItemResponse(field: $field, messages: $messages), ...$nested];
    }
}

<?php
declare(strict_types=1);
namespace Flex\Econt;

use Flex\Contracts\Http\ResponseFactoryInterface;
use Flex\Http\RequestInput;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

final readonly class AdminController
{
    public function __construct(private ResponseFactoryInterface $responses, private RequestInput $input) {}

    public function __invoke(ServerRequestInterface $request, array $arguments = []): ResponseInterface
    {
        $settings = new EcontSettings();
        $client = new EcontClient($settings);
        try {
            $path = $request->getUri()->getPath();
            if (str_ends_with($path, '/test-connection')) {
                return $this->responses->json($client->testConnection(), 200, ['Cache-Control' => 'no-store']);
            }
            if ($request->getMethod() === 'GET') {
                $settings->ensureDemoDefaults();

                return $this->responses->json(['settings' => $settings->publicSettings()], 200, ['Cache-Control' => 'no-store']);
            }
            $saved = $settings->save($this->input->all($request));

            return $this->responses->json(['settings' => $saved], 200, ['Cache-Control' => 'no-store']);
        } catch (EcontException $error) {
            return $this->responses->json(['error' => ['message' => $error->getMessage()]], $error->status(), ['Cache-Control' => 'no-store']);
        } catch (\InvalidArgumentException $error) {
            return $this->responses->json(['error' => ['message' => $error->getMessage()]], $error->getCode() ?: 422, ['Cache-Control' => 'no-store']);
        }
    }
}

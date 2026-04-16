<?php

declare(strict_types=1);

namespace DiveShop365\AI\Controller;

use DiveShop365\AI\Enum\ChatAudience;
use DiveShop365\AI\Service\FlowiseQueryService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\Security\Permission;

/**
 * Proxy endpoint between the CMS DeepChat widget and Flowise.
 *
 * Requires an active CMS session. Queries are always scoped to
 * ChatAudience::Cms — CMS users only see CMS-published content.
 *
 * Route: POST ai-chatbot/chat
 *
 * ENV:
 *   FLOWISE_URL         Base URL of your Flowise instance (no trailing slash)
 *   FLOWISE_CHATFLOW_ID The chatflow UUID from Flowise
 */
class ChatbotController extends Controller
{
    private static array $allowed_actions = ['chat'];

    private static array $url_handlers = ['chat' => 'chat'];

    public function chat(HTTPRequest $request): HTTPResponse
    {
        $response = $this->getResponse();
        $response->addHeader('Content-Type', 'application/json');

        if (!$request->isPOST()) {
            return $response->setStatusCode(405)->setBody(\json_encode(['error' => 'Method not allowed']));
        }

        if (!Permission::check('CMS_ACCESS_CMSMain')) {
            return $response->setStatusCode(403)->setBody(\json_encode(['error' => 'Forbidden']));
        }

        $body     = \json_decode($request->getBody(), true) ?? [];
        $messages = $body['messages'] ?? [];
        $last     = !empty($messages) ? end($messages) : [];
        $question = $last['text'] ?? '';

        if (empty($question)) {
            return $response->setStatusCode(422)->setBody(\json_encode(['error' => 'No message provided']));
        }

        $flowiseUrl = Environment::getEnv('FLOWISE_URL');
        $chatflowId = Environment::getEnv('FLOWISE_CHATFLOW_ID');

        if (!$flowiseUrl || !$chatflowId) {
            return $response->setStatusCode(503)->setBody(\json_encode([
                'error' => 'Chatbot not configured — set FLOWISE_URL and FLOWISE_CHATFLOW_ID in .env',
            ]));
        }

        $sessionId = \session_id() ?: 'cms-default';
        $payload   = (new FlowiseQueryService(ChatAudience::Cms))->buildPayload($question, $sessionId);

        try {
            $flowiseResponse = (new Client(['timeout' => 60]))->post(
                \rtrim($flowiseUrl, '/') . '/api/v1/prediction/' . $chatflowId,
                ['headers' => ['Content-Type' => 'application/json'], 'json' => $payload]
            );

            $answer = \json_decode((string) $flowiseResponse->getBody(), true)['text'] ?? 'Sorry, I could not get a response.';

            return $response->setStatusCode(200)->setBody(\json_encode(['text' => $answer]));

        } catch (GuzzleException $e) {
            return $response->setStatusCode(500)->setBody(\json_encode([
                'error' => 'Failed to reach AI service: ' . $e->getMessage(),
            ]));
        } catch (\Throwable) {
            return $response->setStatusCode(500)->setBody(\json_encode(['error' => 'Internal error']));
        }
    }
}

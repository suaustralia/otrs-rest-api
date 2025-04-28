<?php

namespace SUA\Otrs;

use Exception;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Znuny Rest API
 */
class OtrsRestApi
{
    private readonly HttpClientInterface $httpClient;
    private array $pendingAttachments = [];

    public function __construct(
        string $url,
        private readonly string $username,
        private readonly string $password,
    ) {
        $this->httpClient = HttpClient::createForBaseUri($url);
    }

    /**
     * Create a new ticket using the TicketCreate API
     * See http://doc.znuny.com/doc/api/znuny/6.0/Perl/Kernel/GenericInterface/Operation/Ticket/TicketCreate.pm.html
     *
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws Exception
     */
    public function createTicket(
        string $title,
        string $customer,
        string $subject,
        string $body,
        ?string $from,
        string $contentType = 'text/plain; charset=ISO-8859-1',
        string $communicationChannel = 'Internal',
        ?string $queueName = null,
        ?int $queueId = null,
        array $extraTicketData = [],
        array $extraArticleData = [],
    ): array {
        if (strlen(trim($title)) === 0) {
            throw new Exception('Need a title. Title is empty');
        }

        $ticketDefaults = [
            'LockState'  => 'unlock',
            'PriorityID' => 2,
            'State'      => 'new',
        ];
        $ticketBody = array_merge($ticketDefaults, $extraTicketData);
        $ticketBody['CustomerUser'] = $customer;
        $ticketBody['Title'] = $title;
        if ($queueId) {
            $ticketBody['QueueID'] = $queueId;
        } else {
            $ticketBody['Queue'] = $queueName;
        }

        $articleBody = $this->generateArticleBody(
            $title,
            $subject,
            $body,
            $from,
            $contentType,
            $communicationChannel,
            $extraArticleData
        );

        $requestData = [
            'Ticket'  => $ticketBody,
            'Article' => $articleBody,
        ];

        return $this->send($requestData, 'Ticket', 'POST');
    }

    /**
     * Add an article to an existing ticket. It uses the TicketUpdate API
     * http://doc.znuny.com/doc/api/znuny/6.0/Perl/Kernel/GenericInterface/Operation/Ticket/TicketUpdate.pm.html
     *
     * @param int         $ticketId
     * @param string      $createdBy
     * @param string      $subject
     * @param string      $body
     * @param string|null $from
     * @param string      $contentType
     * @param string      $communicationChannel
     * @param array       $extraArticleData
     *
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     * @throws Exception
     */
    public function addArticle(
        int $ticketId,
        string $createdBy,
        string $subject,
        string $body,
        ?string $from,
        string $contentType = 'text/plain; charset=ISO-8859-1',
        string $communicationChannel = 'Internal',
        array $extraArticleData = [],
    ): void {
        $articleBody = $this->generateArticleBody(
            $createdBy,
            $subject,
            $body,
            $from,
            $contentType,
            $communicationChannel,
            $extraArticleData
        );

        $requestData = [
            'TicketID' => $ticketId,
            'Ticket'   => [
                'State' => 'open',
            ],
            'Article'  => $articleBody,
        ];

        $this->send($requestData, 'Ticket/' . $ticketId, 'PATCH');
    }

    /**
     * Attach a file to the next request (createTicket/updateTicket)
     */
    public function attachFileToNextRequest(string $filePath, string $fileName, string $mimeType): void
    {
        $this->pendingAttachments[] = [
            'Content'     => base64_encode(file_get_contents($filePath)),
            'ContentType' => $mimeType,
            'Filename'    => $fileName,
        ];
    }

    /**
     * Get the ticket number
     *
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getTicketNumber(int $ticketId): string
    {
        $ticket = $this->getTicket($ticketId);

        return number_format($ticket['ticketNumber'], 0, '.', '');
    }

    /**
     * Get ticket information
     *
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getTicket(int $ticketId, bool $extended = false): array
    {
        return $this->send(['Extended' => (int) $extended], 'Ticket/' . $ticketId, 'GET')['ticket'][0];
    }

    /**
     * Creates a new Session and returns the SessionID. Useful to check if the login works
     *
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function sessionCreate(): string
    {
        return $this->send([], 'Session', 'POST')['sessionId'];
    }

    /**
     * Send the data to the ticket system and process the response
     *
     * @param string $method POST|GET|PATCH
     *
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws Exception
     */
    private function send(array $requestData, string $path, string $method): array
    {
        $body = array_merge(
            $requestData,
            [
                'UserLogin' => $this->username,
                'Password'  => $this->password,
            ]
        );

        /*
         * GET method can't have a body, so we have to send all data in the parameters
         * thanks to the invalid bug fix at https://bugs.znuny.org/show_bug.cgi?id=14203
         */
        if ($method === 'GET') {
            $requestUrl = $path . '?' . http_build_query($body);
            $result = $this->httpClient->request($method, $requestUrl);
        } else {
            $requestUrl = $path;
            if (count($this->pendingAttachments)) {
                $body['Attachment'] = $this->pendingAttachments;
            }
            $result = $this->httpClient->request($method, $requestUrl, ['json' => $body]);
        }

        $responseBody = $result->getContent(throw: false);
        // this checks for an issue with the HTTP request, not an error from Znuny
        if ($result->getStatusCode() >= 400) {
            throw new Exception($responseBody);
        }

        // this checks for Znuny errors
        $responseValues = $this->normalizeResponse(json_decode($responseBody, true));
        if (isset($responseValues['error'])) {
            throw new Exception($responseValues['error']['errorMessage']);
        }

        // clear all pending attachments
        $this->pendingAttachments = [];

        return $responseValues;
    }

    /**
     * @throws Exception
     */
    private function generateArticleBody(
        string $createdBy,
        string $subject,
        string $body,
        ?string $from,
        string $contentType = 'text/plain; charset=ISO-8859-1',
        string $communicationChannel = 'Internal',
        array $extraArticleData = [],
    ): array {
        if (strlen(trim($subject)) === 0) {
            throw new Exception('Need a subject. Subject is empty');
        }

        if (strlen(trim($body)) === 0) {
            throw new Exception('Need a body. Body is empty');
        }

        $articleBody = array_merge(
            [
                'Subject'              => $subject,
                'Body'                 => $body,
                'CommunicationChannel' => $communicationChannel,
                'ContentType'          => $contentType,
                'HistoryType'          => 'WebRequestCustomer',
                'HistoryComment'       => $createdBy,
                'SenderType'           => 'system',
            ],
            $extraArticleData
        );

        if ($from) {
            $articleBody['From'] = $from;
        }

        switch ($communicationChannel) {
            case 'note-internal':
                $articleBody['NoAgentNotify'] = 1;
                $articleBody['CommunicationChannel'] = 'Internal';
                break;
            case 'Internal':
                $articleBody = array_merge(
                    $articleBody,
                    [
                        'Loop'             => 0,
                        'AutoResponseType' => 'auto reply',
                        'OrigHeader'       => [
                            'From'    => $from,
                            'To'      => 'Postmaster',
                            'Subject' => $subject,
                            'Body'    => $body,
                        ],
                    ]
                );
                break;
        }

        return $articleBody;
    }

    private function normalizeResponse(array $body): array
    {
        $newBody = [];
        foreach ($body as $key => $value) {
            $newKey = is_int($key) ? $key : $this->fixCamelCase($key);
            $newBody[$newKey] = is_array($value) ? $this->normalizeResponse($value) : $value;
        }

        return $newBody;
    }

    private function fixCamelCase(string $string): string
    {
        $string = (string) preg_replace('/([a-z])ID/', '$1Id', $string);

        return strtolower(substr($string, 0, 1)) . substr($string, 1);
    }
}

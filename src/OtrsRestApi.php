<?php

namespace SUA\Otrs;

use Exception;
use Httpful\Request;
use Httpful\Response;

/**
 * Znuny Rest API
 */
class OtrsRestApi
{
    private array $pendingAttachments = [];

    public function __construct(
        private readonly string $url,
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    /**
     * Create a new ticket using the TicketCreate API
     * See http://doc.znuny.com/doc/api/znuny/6.0/Perl/Kernel/GenericInterface/Operation/Ticket/TicketCreate.pm.html
     *
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
    ): array|string|object {
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

        return $this->send($requestData, 'Ticket')->body;
    }

    /**
     * Add an article to an existing ticket. It uses the TicketUpdate API
     * http://doc.znuny.com/doc/api/znuny/6.0/Perl/Kernel/GenericInterface/Operation/Ticket/TicketUpdate.pm.html
     *
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
    ): Response {
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

        return $this->send($requestData, 'Ticket/' . $ticketId, 'patch');
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
     * @throws Exception
     */
    public function getTicketNumber(int $ticketId): string
    {
        $ticket = $this->getTicket($ticketId);

        return number_format($ticket->TicketNumber, 0, '.', '');
    }

    /**
     * Get ticket information
     *
     * @throws Exception
     */
    public function getTicket(int $ticketId, bool $extended = false): mixed
    {
        $response = $this->send(['Extended', (int) $extended], 'Ticket/' . $ticketId, 'get');

        return $response->body->Ticket[0];
    }

    /**
     * Creates a new Session and returns the SessionID. Useful to check if the login works
     *
     * @throws Exception
     */
    public function sessionCreate(): string
    {
        $response = $this->send([], 'Session');

        return $response->body->SessionID;
    }

    /**
     * Send the data to the ticket system and process the response
     *
     * @param string $method post|get|patch
     *
     * @throws Exception
     */
    private function send(array $requestData, string $path, string $method = 'post'): Response
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
        if ($method === 'get') {
            $requestUrl = $this->url . $path . '?' . http_build_query($body);
            $body = null;
        } else {
            $requestUrl = $this->url . $path;
            if (count($this->pendingAttachments)) {
                $body['Attachment'] = $this->pendingAttachments;
            }
        }

        /** @var Response $result */
        $result = Request::$method($requestUrl)
            ->body($body)
            ->sendsJson()
            ->send();

        // this checks for an issue with the HTTP request, not an error from Znuny
        if ($result->hasErrors()) {
            throw new Exception($result);
        }

        // this checks for Znuny errors
        if (property_exists($result->body, 'Error')) {
            throw new Exception($result->body->Error->ErrorMessage);
        }

        // clear all pending attachments
        $this->pendingAttachments = [];

        return $result;
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
                'HistoryType',
                'WebRequestCustomer',
                'HistoryComment',
                $createdBy,
                'SenderType',
                'system',
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
                        'Loop',
                        0,
                        'AutoResponseType',
                        'auto reply',
                        'OrigHeader',
                        [
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
}

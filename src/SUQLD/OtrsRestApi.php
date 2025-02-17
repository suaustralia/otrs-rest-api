<?php

namespace SUQLD;

use Exception;
use Httpful\Request;
use Httpful\Response;

/**
 * Znuny/OTRS Rest API
 */
class OtrsRestApi
{

    private array $pendingAttachments = [];

    /**
     * @param string $url
     * @param string $username
     * @param string $password
     */
    public function __construct(
        private readonly string $url,
        private readonly string $username,
        private readonly string $password
    ) {
    }

    /**
     * Send the data to the Ticket System and process the response
     *
     * @param array  $requestData
     * @param string $path
     * @param string $method post|get|patch etc
     * @return Response
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

        /* GET method can't have a body so we have to send all data in the parameters
         * thanks to the invalid bug fix at https://bugs.otrs.org/show_bug.cgi?id=14203
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

        // This checks for an issue with the HTTP request, not an error from OTRS
        if ($result->hasErrors()) {
            throw new Exception($result);
        }

        // This checks for OTRS errors
        if (property_exists($result->body, 'Error')) {
            throw new Exception($result->body->Error->ErrorMessage);
        }

        // Clear all pending attachments
        $this->pendingAttachments = [];

        return $result;
    }

    /**
     * @param string      $createdBy
     * @param string      $subject
     * @param string      $body
     * @param string|null $from
     * @param string      $contentType
     * @param string      $communicationChannel
     * @param array       $extraArticleData
     * @return array
     * @throws Exception
     */
    private function generateArticleBody(
        string $createdBy,
        string $subject,
        string $body,
        string|null $from,
        string $contentType = 'text/plain; charset=ISO-8859-1',
        string $communicationChannel = 'Internal',
        array $extraArticleData = []
    ): array {

        if (strlen(trim($subject)) == 0) {
            throw new Exception('Need a subject. Subject is empty');
        }
        if (strlen(trim($body)) == 0) {
            throw new Exception('Need a body. Body is empty');
        }


        $articleBody = array_merge(
            [
                'Subject'              => $subject,
                'Body'                 => $body,
                'CommunicationChannel' => $communicationChannel,
                'ContentType'          => $contentType,
                "HistoryType",
                "WebRequestCustomer",
                "HistoryComment",
                $createdBy,
                "SenderType",
                "system",
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
                        "Loop",
                        0,
                        "AutoResponseType",
                        'auto reply',
                        "OrigHeader",
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

    /**
     *
     * Create a new ticket using the TicketCreate API
     * http://doc.otrs.com/doc/api/otrs/6.0/Perl/Kernel/GenericInterface/Operation/Ticket/TicketCreate.pm.html
     *
     * @param string             $title
     * @param int|string|numeric $queue
     * @param string             $customer
     * @param string             $subject
     * @param string             $body
     * @param string|null        $from
     * @param string             $contentType
     * @param string             $communicationChannel
     * @param array              $extraTicketData
     * @param array              $extraArticleData
     * @return array|string|object
     * @throws Exception
     */
    public function createTicket(
        string $title,
        float|int|string $queue, // ID or String
        string $customer, // string
        // Article parts
        string $subject,
        string $body,
        string|null $from,
        string $contentType = 'text/plain; charset=ISO-8859-1',
        string $communicationChannel = 'Internal',
        array $extraTicketData = [],
        array $extraArticleData = []
    ): array|string|object {
        $ticketDefaults = [
            'LockState'  => 'unlock',
            'PriorityID' => 2,
            'State'      => 'new',
        ];
        if (strlen(trim($title)) == 0) {
            throw new Exception('Need a title. Title is empty');
        }


        $ticketBody = array_merge($ticketDefaults, $extraTicketData);
        $ticketBody['CustomerUser'] = $customer;
        $ticketBody['Title'] = $title;
        if (is_numeric($queue)) {
            $ticketBody['QueueID'] = $queue;
        } else {
            $ticketBody['Queue'] = $queue;
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
            "Ticket"  => $ticketBody,
            "Article" => $articleBody,
        ];

        return $this->send($requestData, 'Ticket')->body;
    }

    /**
     * Add an article to an existing ticket. It uses the TicketUpdate API
     * http://doc.otrs.com/doc/api/otrs/6.0/Perl/Kernel/GenericInterface/Operation/Ticket/TicketUpdate.pm.html
     *
     * @param int         $ticketID
     * @param string      $createdBy
     * @param string      $subject
     * @param string      $body
     * @param string|null $from
     * @param string      $contentType
     * @param string      $communicationChannel
     * @param array       $extraArticleData
     * @return Response
     * @throws Exception
     */
    public function addArticle(
        int $ticketID,
        string $createdBy,
        // Article parts
        string $subject,
        string $body,
        string|null $from,
        string $contentType = 'text/plain; charset=ISO-8859-1',
        string $communicationChannel = 'Internal',
        array $extraArticleData = []
    ): Response
    {

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
            "TicketID" => $ticketID,
            "Ticket"   => [
                'State' => 'open',
            ],
            "Article"  => $articleBody,
        ];

        return $this->send($requestData, 'Ticket/' . $ticketID, 'patch');
    }

    /**
     * Attach file to next request (createTicket/updateTicket)
     *
     * @param string $filePath
     * @param string $fileName
     * @param string $mimeType
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
     * Get the Ticket Number
     *
     * @param int $TicketID
     * @return string TicketNumber
     * @throws Exception
     */
    public function getTicketNumber(int $TicketID): string
    {
        $ticket = $this->getTicket($TicketID);

        return number_format($ticket->TicketNumber, 0, '.', '');
    }

    /**
     * Get Ticket Information
     *
     * @param int $TicketID
     * @param bool $Extended
     * @return mixed
     * @throws Exception
     */
    public function getTicket(int $TicketID, bool $Extended = false): mixed
    {
        $requestBody = [
            'Extended',
            (int) $Extended,
        ];

        $response = $this->send($requestBody, 'Ticket/' . $TicketID, 'get');

        return $response->body->Ticket[0];
    }

    /**
     * Creates a new Session and returns the SessionID. Useful to check if the login works
     *
     * @return string
     * @throws Exception
     */
    public function sessionCreate(): string
    {
        $response = $this->send([], 'Session', 'post');
        return $response->body->SessionID;
    }
}

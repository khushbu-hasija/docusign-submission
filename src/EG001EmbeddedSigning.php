<?php
/**
 * Example 001: Use embedded signing
 */

namespace Example;

use DocuSign\eSign\Client\ApiException;
use DocuSign\eSign\Model\Document;
use DocuSign\eSign\Model\EnvelopeDefinition;
use DocuSign\eSign\Model\Recipients;
use DocuSign\eSign\Model\Signer;
use DocuSign\eSign\Model\SignHere;
use DocuSign\eSign\Model\Tabs;
use Example\Controllers\eSignBaseController;
use Example\Services\SignatureClientService;
use Example\Services\RouterService;

class EG001EmbeddedSigning extends eSignBaseController
{
    /** signatureClientService */
    private $clientService;

    /** RouterService */
    private $routerService;

    /** Specific template arguments */
    private $args;

    private $eg = "eg001";            # reference (and url) for this example
    private $signer_client_id = 1000; # Used to indicate that the signer will use embedded
                                      # Signing. Represents the signer's userId within your application.

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->args = $this->getTemplateArgs();
        $this->clientService = new SignatureClientService($this->args);
        $this->routerService = new RouterService();
        parent::controller($this->eg, $this->routerService, basename(__FILE__));
    }

    /**
     * 1. Check the token
     * 2. Call the worker method
     * 3. Redirect the user to the signing
     *
     * @return void
     * @throws ApiException for API problems and perhaps file access \Exception too.
     */
    public function createController(): void
    {
        $minimum_buffer_min = 3;
        if ($this->routerService->ds_token_ok($minimum_buffer_min)) {
            # 1. Call the worker method
            # More data validation would be a good idea here
            # Strip anything other than characters listed
            $results = $this->worker($this->args);

            if ($results) {
                # Redirect the user to the embedded signing
                # Don't use an iFrame!
                # State can be stored/recovered using the framework's session or a
                # query parameter on the returnUrl (see the make recipient_view_request method)
                header('Location: ' . $results["redirect_url"]);
                exit;
            }
        } else {
            $this->clientService->needToReAuth($this->eg);
        }
    }


    /**
     * Do the work of the example
     * 1. Create the envelope request object
     * 2. Send the envelope
     * 3. Create the Recipient View request object
     * 4. Obtain the recipient_view_url for the embedded signing
     *
     * @param  $args array
     * @return array ['redirect_url']
     * @throws ApiException for API problems and perhaps file access \Exception too.
     */
    # ***DS.snippet.0.start
    public function worker(array $args): array
    {
        # 1. Create the envelope request object
        $envelope_definition = $this->make_envelope($args["envelope_args"]);
        $envelope_api = $this->clientService->getEnvelopeApi();

        # 2. call Envelopes::create API method
        # Exceptions will be caught by the calling function
        try {
            $results = $envelope_api->createEnvelope($args['account_id'], $envelope_definition);
        } catch (ApiException $e) {
            $this->clientService->showErrorTemplate($e);
            exit;
        }
        $envelope_id = $results->getEnvelopeId();

        # 3. Create the Recipient View request object
        $authentication_method = 'None'; # How is this application authenticating
        # the signer? See the `authentication_method' definition
        # https://developers.docusign.com/esign-rest-api/reference/Envelopes/EnvelopeViews/createRecipient
        $recipient_view_request = $this->clientService->getRecipientViewRequest(
            $authentication_method,
            $args["envelope_args"]
        );

        # 4. Obtain the recipient_view_url for the embedded signing
        # Exceptions will be caught by the calling function
        $results = $this->clientService->getRecipientView($args['account_id'], $envelope_id, $recipient_view_request);

        return ['envelope_id' => $envelope_id, 'redirect_url' => $results['url']];
    }

    /**
     *  Creates envelope definition
     *  Parameters for the envelope: signer_email, signer_name, signer_client_id
     *
     * @param  $args array
     * @return EnvelopeDefinition -- returns an envelope definition
     */
    private function make_envelope(array $args): EnvelopeDefinition
    {
        # document 1 (pdf) has tag /sn1/
        #
        # The envelope has one recipient.
        # recipient 1 - signer
        #
        # Read the file
        $content_bytes = file_get_contents(self::DEMO_DOCS_PATH . $GLOBALS['DS_CONFIG']['doc_pdf']);
        $base64_file_content = base64_encode($content_bytes);

        # Create the document model
        $document = new Document([ # create the DocuSign document object
            'document_base64' => $base64_file_content,
            'name' => 'Hello World', # can be different from actual file name
            'file_extension' => 'pdf', # many different document types are accepted
            'document_id' => 1 # a label used to reference the doc
        ]);

        # Create the signer recipient model
        $signer = new Signer([ # The signer
            'email' => $args['signer_email'], 'name' => $args['signer_name'],
            'recipient_id' => "1", 'routing_order' => "1",
            # Setting the client_user_id marks the signer as embedded
            'client_user_id' => $args['signer_client_id']
        ]);

        # Create a sign_here tab (field on the document)
        $sign_here = new SignHere([ # DocuSign SignHere field/tab
            'anchor_string' => '/sn1/', 'anchor_units' => 'pixels',
            'anchor_y_offset' => '10', 'anchor_x_offset' => '20'
        ]);

        # Add the tabs model (including the sign_here tab) to the signer
        # The Tabs object wants arrays of the different field/tab types
        $signer->settabs(new Tabs(['sign_here_tabs' => [$sign_here]]));

        # Next, create the top level envelope definition and populate it.
        $envelope_definition = new EnvelopeDefinition([
            'email_subject' => "Please sign this document sent from the PHP SDK",
            'documents' => [$document],
            # The Recipients object wants arrays for each recipient type
            'recipients' => new Recipients(['signers' => [$signer]]),
            'status' => "sent" # requests that the envelope be created and sent.
        ]);

        return $envelope_definition;
    }
    # ***DS.snippet.0.end


    /**
     * Get specific template arguments
     *
     * @return array
     */
    private function getTemplateArgs(): array
    {
        $signer_name = preg_replace('/([^\w \-\@\.\,])+/', '', $_POST['signer_name']);
        $signer_email = preg_replace('/([^\w \-\@\.\,])+/', '', $_POST['signer_email']);
        $envelope_args = [
            'signer_email' => $signer_email,
            'signer_name' => $signer_name,
            'signer_client_id' => $this->signer_client_id,
            'ds_return_url' => $GLOBALS['app_url'] . 'index.php?page=ds_return'
        ];
        $args = [
            'account_id' => $_SESSION['ds_account_id'],
            'base_path' => $_SESSION['ds_base_path'],
            'ds_access_token' => $_SESSION['ds_access_token'],
            'envelope_args' => $envelope_args
        ];

        return $args;
    }
}

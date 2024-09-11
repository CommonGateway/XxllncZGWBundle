<?php
/**
 * This class handles the command for the synchronization of a ZGW Zaak to XXLLNC zaaksysteem v2.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Command
 */

namespace CommonGateway\XxllncZGWBundle\Command;

use App\Entity\Action;
use App\Entity\Gateway as Source;
use CommonGateway\XxllncZGWBundle\Service\ZGWToXxllncService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use CommonGateway\CoreBundle\Service\CallService;

class SendZaakTypeToXxllncCommand extends Command
{

    /**
     * The actual command.
     *
     * @var static
     */
    protected static $defaultName = 'xxllnc:zaaktype:send';

    /**
     * The ZGWToXxllncService.
     *
     * @var ZGWToXxllncService
     */
    private ZGWToXxllncService $zgwToXxllncService;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SessionInterface
     */
    private SessionInterface $session;

    /**
     * @var CallService $callService.
     */
    private CallService $callService;



    /**
     * Class constructor.
     *
     * @param ZGWToXxllncService     $zgwToXxllncService
     * @param EntityManagerInterface $entityManager
     * @param SessionInterface       $session
     */
    public function __construct(
        ZGWToXxllncService $zgwToXxllncService,
        EntityManagerInterface $entityManager,
        SessionInterface $session,
        CallService $callService
    ) {
        $this->zgwToXxllncService   = $zgwToXxllncService;
        $this->entityManager = $entityManager;
        $this->session       = $session;
        $this->callService    = $callService;
        parent::__construct();

    }//end __construct()


    /**
     * Configures this command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers ZGWToXxllncService')
            ->setHelp('This command triggers ZGWToXxllncService')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'ZaakType id to send from gateway to xxllnc'
            );

    }//end configure()


    /**
     * Executes zgwToXxllncService->zaakHandler or zgwToXxllncService->getZaak if a id is given.
     *
     * @param InputInterface  Handles input from cli
     * @param OutputInterface Handles output from cli
     *
     * @return int 0 for failure, 1 for success
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $this->zgwToXxllncService->setStyle($style);
        $zaakId = $input->getArgument('id');

        $actionReference = 'https://development.zaaksysteem.nl/action/xxllnc.ZGWZaakToXxllnc.action.json';
        $action = $this->entityManager->getRepository(Action::class)->findOneBy(['reference' => $actionReference]);
        if ($action instanceof Action === null) {
            $style->error("Action with reference $actionReference not found");

            return Command::FAILURE;
        }

        $source = $this->entityManager->getRepository(Source::class)->findOneBy(['reference' => 'https://development.zaaksysteem.nl/source/xxllnc.zaaksysteemv2.source.json']);


            $result = $this->callService->call(
                $source,
                '/admin/catalog/create_versioned_casetype',
                'POST',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body'    => json_encode([
                        "casetype_uuid" => "cfc89902-8ea0-4b48-95e9-84bbf0214052",
                        "casetype_version_uuid" => "d113b87e-a376-48f8-83c3-3df5c3125772",
                        "catalog_folder_uuid" => "4485b116-79fb-45bd-9f58-6a821883820d",
                        "active" => true,
                        "general_attributes" => [
                              "name" => "Test from code barry api v2",
                              "identification" => "Test",
                              "tags" => "Test",
                              "description" => "Test",
                              "case_summary" => "Test",
                              "case_public_summary" => "Test",
                              "legal_period" => [
                                 "type" => "kalenderdagen",
                                 "value" => "14"
                              ],
                              "service_period" => [
                                    "type" => "kalenderdagen",
                                    "value" => "14"
                                 ]
                           ],
                        "documentation" => [
                                       "process_description" => "www.test.nl",
                                       "initiator_type" => "aangaan",
                                       "motivation" => "Test",
                                       "purpose" => "Test",
                                       "archive_classification_code" => "Test",
                                       "designation_of_confidentiality" => "Openbaar",
                                       "responsible_subject" => "Test",
                                       "responsible_relationship" => "Test",
                                       "possibility_for_objection_and_appeal" => true,
                                       "publication" => true,
                                       "publication_text" => "Test",
                                       "bag" => true,
                                       "lex_silencio_positivo" => true,
                                       "may_postpone" => true,
                                       "may_extend" => true,
                                       "extension_period" => 123,
                                       "adjourn_period" => 123,
                                       "penalty_law" => true,
                                       "wkpb_applies" => true,
                                       "e_webform" => "Test",
                                       "legal_basis" => "test",
                                       "local_basis" => "Test",
                                       "gdpr" => [
                                          "enabled" => true,
                                          "source" => [
                                             "registration" => true,
                                             "sender" => false,
                                             "partner" => false,
                                             "public_source" => false
                                          ],
                                          "kind" => [
                                                "basic_details" => true,
                                                "personal_id_number" => false,
                                                "income" => false,
                                                "race_or_ethniticy" => false,
                                                "political_views" => false,
                                                "religion" => false,
                                                "membership_union" => false,
                                                "genetic_or_biometric_data" => false,
                                                "health" => false,
                                                "sexual_identity" => false,
                                                "criminal_record" => false,
                                                "offspring" => false
                                             ],
                                          "processing_type" => "Delen",
                                          "process_foreign_country" => true,
                                          "process_foreign_country_reason" => "Test",
                                          "processing_legal" => "Toestemming",
                                          "processing_legal_reason" => "Test"
                                       ]
                                    ],
                        "relations" => [
                                                   "allowed_requestor_types" => [
                                                      "natuurlijk_persoon",
                                                      "natuurlijk_persoon_na",
                                                      "niet_natuurlijk_persoon",
                                                      "medewerker"
                                                   ],
                                                   "address_requestor_use_as_case_address" => true,
                                                   "address_requestor_use_as_correspondence" => true,
                                                   "address_requestor_show_on_map" => true
                                                ],
                        "registrationform" => [
                                                         "allow_assigning_to_self" => true,
                                                         "allow_assigning" => true,
                                                         "show_confidentionality" => true,
                                                         "show_contact_details" => true,
                                                         "allow_add_relations" => true
                                                      ],
                        "webform" => [
                                                            "public_confirmation_title" => "Uw zaak is geregistreerd",
                                                            "public_confirmation_message" => "Bedankt voor het [[case.casetype.initiator_type]] van een <strong>[[case.casetype.name]]</strong>. Uw registratie is bij ons bekend onder <strong>zaaknummer [[case.number]]</strong>. Wij verzoeken u om bij verdere communicatie dit zaaknummer te gebruiken. De behandeling van deze zaak zal spoedig plaatsvinden.",
                                                            "case_location_message" => "",
                                                            "pip_view_message" => "Ook kunt u op elk moment van de dag de voortgang en inhoud inzien via de persoonlijke internetpagina.",
                                                            "actions" => [
                                                               "enable_webform" => false,
                                                               "create_delayed" => false,
                                                               "address_check" => false,
                                                               "reuse_casedata" => false,
                                                               "enable_online_payment" => false,
                                                               "enable_manual_payment" => false,
                                                               "email_required" => false,
                                                               "phone_required" => false,
                                                               "mobile_required" => false,
                                                               "disable_captcha" => false,
                                                               "generate_pdf_end_webform" => false
                                                            ],
                                                            "price" => [
                                                                  "web" => "",
                                                                  "frontdesk" => "",
                                                                  "phone" => "",
                                                                  "email" => "",
                                                                  "assignee" => "",
                                                                  "post" => ""
                                                               ]
                                                         ],
                        "case_dossier" => [
                                                                     "disable_pip_for_requestor" => true,
                                                                     "lock_registration_phase" => true,
                                                                     "queue_coworker_changes" => true,
                                                                     "allow_external_task_assignment" => true,
                                                                     "default_document_folders" => [
                                                                     ]
                                                                  ],
                        "api" => [
                                                                           "api_can_transition" => true,
                                                                           "notifications" => [
                                                                              "external_notify_on_new_case" => true,
                                                                              "external_notify_on_new_document" => true,
                                                                              "external_notify_on_new_message" => true,
                                                                              "external_notify_on_exceed_term" => true,
                                                                              "external_notify_on_allocate_case" => true,
                                                                              "external_notify_on_phase_transition" => true,
                                                                              "external_notify_on_task_change" => true,
                                                                              "external_notify_on_label_change" => true,
                                                                              "external_notify_on_subject_change" => true
                                                                           ]
                                                                        ],
                        "phases" => [
                                                                                 [
                                                                                    "phase_name" => "Registreren",
                                                                                    "milestone_name" => "Geregistreerd",
                                                                                    "term_in_days" => 0,
                                                                                    "assignment" => [
                                                                                       "department_name" => "GBT",
                                                                                       "department_uuid" => "a5f2ff05-fb6d-449d-bd1e-3a92c5a3dc55",
                                                                                       "role_name" => "Behandelaar",
                                                                                       "role_uuid" => "d3d745fd-3aef-43d1-89b5-c041c2120cb3",
                                                                                       "enabled" => true
                                                                                    ],
                                                                                    "custom_fields" => [
                                                                                          [
                                                                                             "order" => 1,
                                                                                             "is_group" => true,
                                                                                             "attribute_type" => "group",
                                                                                             "title" => "Benodigde gegevens",
                                                                                             "help_intern" => "Vul de benodigde velden in voor uw zaak",
                                                                                             "publish_pip" => false
                                                                                          ],
                                                                                          [
                                                                                                "is_group" => false,
                                                                                                "uuid" => "f13d8d3b-879e-4a49-9b34-0b6c6137dcf5",
                                                                                                "attribute_type" => "option",
                                                                                                "title" => null,
                                                                                                "attribute_name" => "Enkelvoudige keuze_hayo",
                                                                                                "attribute_magic_string" => "enkelvoudige_keuze_hayo",
                                                                                                "mandatory" => false,
                                                                                                "help_intern" => null,
                                                                                                "help_extern" => null,
                                                                                                "use_as_case_address" => false,
                                                                                                "referential" => false,
                                                                                                "publish_pip" => false,
                                                                                                "pip_can_change" => false,
                                                                                                "skip_change_approval" => false,
                                                                                                "system_attribute" => false,
                                                                                                "permissions" => [
                                                                                                ],
                                                                                                "is_multiple" => false,
                                                                                                "label_multiple" => null,
                                                                                                "show_on_map" => false,
                                                                                                "create_custom_object_attribute_mapping" => [
                                                                                                   ],
                                                                                                "order" => 2
                                                                                             ],
                                                                                          [
                                                                                                         "order" => 3,
                                                                                                         "is_group" => false,
                                                                                                         "attribute_type" => "textblock",
                                                                                                         "title" => "Deze zie niet wanneer de regel Ja is, anders wel",
                                                                                                         "help_intern" => "",
                                                                                                         "publish_pip" => false
                                                                                                      ],
                                                                                          [
                                                                                                            "order" => 4,
                                                                                                            "is_group" => false,
                                                                                                            "attribute_type" => "textblock",
                                                                                                            "title" => "Lex dinges ja",
                                                                                                            "help_intern" => "",
                                                                                                            "publish_pip" => false
                                                                                                         ],
                                                                                          [
                                                                                                               "order" => 5,
                                                                                                               "is_group" => false,
                                                                                                               "attribute_type" => "textblock",
                                                                                                               "title" => "Lex dinges nee",
                                                                                                               "help_intern" => "",
                                                                                                               "publish_pip" => false
                                                                                                            ]
                                                                                       ]
                                                                                 ],
                                                                                 [
                                                                                                                  "phase_name" => "Afhandelen",
                                                                                                                  "milestone_name" => "Afgehandeld",
                                                                                                                  "term_in_days" => 0,
                                                                                                                  "custom_fields" => [
                                                                                                                  ]
                                                                                                               ]
                                                                              ],
                        "authorization" => [
                                                                                                                     ],
                        "child_casetype_settings" => [
                                                                                                                           "enabled" => false,
                                                                                                                           "child_casetypes" => [
                                                                                                                           ]
                                                                                                                        ],
                        "change_log" => [
                                                                                                                                 "update_description" => "Test",
                                                                                                                                 "update_components" => [
                                                                                                                                    "API"
                                                                                                                                 ]
                                                                                                                              ]
                                                                                                                                 ],),
                    'debug' => true
                ],
                false,
                true
            );
        dump($result->getBody()->getContents());
        dump('test');die;

        $this->session->remove('currentActionUserId');
        if ($action->getUserId() !== null && Uuid::isValid($action->getUserId()) === true) {
            $user = $this->entityManager->getRepository('App:User')->find($action->getUserId());
            if ($user instanceof User === true) {
                $this->session->set('currentActionUserId', $action->getUserId());
            }
        }

        if (isset($zaakId) === true
            && Uuid::isValid($zaakId) === true
        ) {
            if ($this->zgwToXxllncService->zgwToXxllncHandler($action->getConfiguration(), $zaakId) === true) {
                return Command::FAILURE;
            }

            isset($style) === true && $style->info("Succesfully synced and created a XXLLNC ZaakType from xxllnc case: $zaakId.");

            return Command::SUCCESS;
        }//end if

        if ($this->zgwToXxllncService->zgwToXxllncHandler([], $action->getConfiguration()) === null) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class

<?php
/**
 * This class handles the installation of the XxllncZGWBundle.
 *
 * By creating symfony objects that function as configuration for the gateway.
 *
 * @author Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>, Wilco Louwerse <wilco@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Service
 */

// src/Service/InstallationService.php
namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Entity;
use App\Entity\Translation;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Psr\Log\LoggerInterface;


class InstallationService implements InstallerInterface
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;
    
    /**
     * The installation logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    
    /**
     * @var ObjectRepository
     */
    private ObjectRepository $translationRepository;
    
    private const TRANSLATIONS = [
        ["translateFrom" => "Nee", "translateTo" => false],
        ["translateFrom" => "Ja", "translateTo" => true],
        ["translateFrom" => "internextern", "translateTo" => "intern"],
        ["translateFrom" => "Vernietigen (V)", "translateTo" => "vernietigen"],
        ["translateFrom" => "Bewaren (B)", "translateTo" => "blijvend_bewaren"],
    ];
    
    
    /**
     * The constructor
     *
     * @param EntityManagerInterface $entityManager The entity manager.
     * @param LoggerInterface $installationLogger The installation logger.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $installationLogger
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $installationLogger;
    
        $this->translationRepository = $this->entityManager->getRepository('App:Translation');
        
    }//end __construct()
    
    
    /**
     * Every installation service should implement an install function
     *
     * @return void
     */
    public function install()
    {
        $this->logger->debug("XxllncZGWBundle -> Install()");
        
        $this->checkDataConsistency();

    }//end install()
    
    
    /**
     * Every installation service should implement an update function
     *
     * @return void
     */
    public function update()
    {
        $this->logger->debug("XxllncZGWBundle -> Update()");
        
        $this->checkDataConsistency();

    }//end update()
    
    
    /**
     * Every installation service should implement an uninstall function
     *
     * @return void
     */
    public function uninstall()
    {
        $this->logger->debug("XxllncZGWBundle -> Uninstall()");
    
        // Do some cleanup to uninstall correctly...

    }//end uninstall()


    /**
     * Checks if we need to create/update objects.
     *
     * @return void
     */
    public function checkDataConsistency(): void
    {
        // Update attributes.
        $this->updateAttributes();

        // Translations.
        $this->createTranslations();

        $this->entityManager->flush();

    }//end checkDataConsistency()


    /**
     * Updates attributes to simple array attributes.
     *
     * @return void
     */
    private function updateAttributes(): void
    {
        // Get xllncZaakPost Schema.
        $reference = 'https://development.zaaksysteem.nl/schema/xxllnc.zaakPost.schema.json';
        $xxllncZaakPost = $this->entityManager->getRepository('App:Entity')->findOneBy(['reference' => $reference]);
        if ($xxllncZaakPost === null) {
            $this->logger->error("No entity found for $reference.", ['plugin' => 'common-gateway/xxllnc-zgw-bundle']);
            
            return;
        }
        
        // Get xllncZaakPost -> files Attribute.
        $xxllncZaakPostFiles = $this->entityManager->getRepository('App:Attribute')->findOneBy(['entity' => $xxllncZaakPost, 'name' => 'files']);
        if ($xxllncZaakPostFiles === null) {
            $this->logger->error("No attribute 'files' found for entity: $reference.", ['plugin' => 'common-gateway/xxllnc-zgw-bundle']);
            
            return;
        }
    
        $xxllncZaakPostFiles->setObject(null);
        $xxllncZaakPostFiles->setMultiple(false);
        $xxllncZaakPostFiles->setType('array');
        $this->entityManager->persist($xxllncZaakPostFiles);

    }//end updateAttributes()


    /**
     * Creates needed translations for xxllnc to zgw.
     *
     * @return void
     *
     * @todo Should be integrated in mapping->translations.
     */
    private function createTranslations(): void
    {
        $this->logger->info("Looking for translations");
        
        foreach ($this::TRANSLATIONS as $translation) {
            $translation['translationTable'] = 'caseTypeTable1';
            $translation['language'] = 'nl';
            $this->createTranslation($translation);
        }

    }//end createTranslations()
    
    /**
     * Create a single translation needed for xxllnc to zgw.
     *
     * @param array $data An array with data, used to check if a Translation already exists, else creating it with this data.
     *
     * @return void
     */
    private function createTranslation(array $data): void
    {
        $translation = $this->translationRepository->findOneBy(['translateFrom' => $data['translateFrom'], 'translationTable' => $data['translationTable']]);
        if ($translation !== null) {
            $this->logger->debug("Translation found", ['id' => $translation->getId()->toString()]);
            
            return;
        }
        
        $translation = new Translation();
        $translation->setTranslationTable($data['translationTable']);
        $translation->setTranslateFrom($data['translateFrom']);
        $translation->setTranslateTo($data['translateTo']);
        $translation->setLanguage($data['language']);
        $this->entityManager->persist($translation);
        
        $this->logger->debug("Translation created", ['id' => $translation->getId()->toString()]);
        
    }//end createTranslation()
}//end class

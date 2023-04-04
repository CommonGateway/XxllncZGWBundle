<?php
/**
 * This class handles the installation of the XxllncZGWBundle.
 *
 * By creating symfony objects that function as configuration for the gateway.
 *
 * @author Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @category Service
 *
 * @todo update to new installation method (check zgwbundle as example)
 */

// src/Service/InstallationService.php
namespace CommonGateway\XxllncZGWBundle\Service;

use App\Entity\Entity;
use App\Entity\Translation;
use CommonGateway\CoreBundle\Installer\InstallerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\Console\Style\SymfonyStyle;


class InstallationService implements InstallerInterface
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * @var SymfonyStyle
     */
    private SymfonyStyle $style;

    /**
     * @var ObjectRepository
     */
    private ObjectRepository $schemaRepository;

    /**
     * @var ObjectRepository
     */
    private ObjectRepository $attributeRepository;

    /**
     * @var ObjectRepository
     */
    private ObjectRepository $translationRepository;


    /**
     * __construct.
     */
    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;

        $this->schemaRepository      = $this->entityManager->getRepository('App:Entity');
        $this->attributeRepository   = $this->entityManager->getRepository('App:Attribute');
        $this->translationRepository = $this->entityManager->getRepository('App:Translation');

    }//end __construct()


    /**
     * Set symfony style in order to output to the console.
     *
     * @param SymfonyStyle $style
     *
     * @return self
     *
     * @todo change to monolog
     */
    public function setStyle(SymfonyStyle $style): self
    {
        $this->style = $style;

        return $this;

    }//end setStyle()


    public function install()
    {
        $this->checkDataConsistency();

    }//end install()


    public function update()
    {
        $this->checkDataConsistency();

    }//end update()


    public function uninstall()
    {
        // Do some cleanup

    }//end uninstall()


    /**
     * Checks if we need to create/update objects.
     *
     * @return void
     */
    public function checkDataConsistency(): void
    {
        // Return if zgw is not installed.
        if ($this->isZGWBundleInstalled() == false) {
            return;
        }

        // Get xllncZaakPost schema.
        $xxllncZaakPost = $this->schemaRepository->findOneBy(['name' => 'XxllncZaakPost']);

        // Update attributes.
        $this->updateAttributes($xxllncZaakPost);

        // Update zgw zaak endpoint to throw event that gets the zaak to xxllnc.
        $this->updateZGWZaakEndpoint($xxllncZaakPost);

        // Translations.
        $this->createTranslations();

        $this->entityManager->flush();

    }//end checkDataConsistency()


    /**
     * Checks if ZGWBundle is installed.
     *
     * If not we cant install this XxllncZGWBundle.
     *
     * @return bool true if installed, false if not
     */
    private function isZGWBundleInstalled()
    {
        $ZGWZaak = $this->schemaRepository->findOneBy(['reference' => 'https://vng.opencatalogi.nl/schemas/zrc.zaak.schema.json']);
        if ($ZGWZaak === null) {
            isset($this->style) === true && $this->style->error('ZGWBundle not installed, please make sure that bundle is installed before this one');

            return false;
        }

        isset($this->style) === true && $this->style->info('ZGWBundle is installed, continueing..');

        return true;

    }//end isZGWBundleInstalled()


    /**
     * Updates attributes to simple array attributes.
     *
     * @param Entity|null $xxllncZaakPost Schema.
     *
     * @return void
     */
    private function updateAttributes(?Entity $xxllncZaakPost): void
    {
        if ($xxllncZaakPostFiles = $this->attributeRepository->findOneBy(['entity' => $xxllncZaakPost, 'name' => 'files'])) {
            $xxllncZaakPostFiles->setObject(null);
            $xxllncZaakPostFiles->setMultiple(false);
            $xxllncZaakPostFiles->setType('array');
            $this->entityManager->persist($xxllncZaakPostFiles);
        }//end if

    }//end updateAttributes()


    /**
     * Updates ZGW Zaak endpoint with a throw event.
     *
     * @return void
     */
    private function updateZGWZaakEndpoint(): void
    {
        isset($this->style) === true && $this->style->writeln(['', '<info>Updating zgw zaak endpoint</info>']);
        $endpoint = $this->entityManager->getRepository('App:Endpoint')->findOneBy(['name' => 'Zaak']);
        $endpoint->setThrows(['zgw.zaak.saved']);
        $this->entityManager->persist($endpoint);

    }//end updateZGWZaakEndpoint()


    /**
     * Creates needed translations for xxllnc to zgw.
     *
     * @return void
     *
     * @todo Should be integrated in mapping
     */
    private function createTranslations(): void
    {
        isset($this->style) === true && $this->style->writeln(['', '<info>Looking for translations</info>']);
        $trans = ($this->translationRepository->findOneBy(['translateFrom' => 'Nee', 'translationTable' => 'caseTypeTable1']) ?? new Translation());
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Nee');
        $trans->setTranslateTo(false);
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->style) === true && $this->style->writeln('Translation created');

        $trans = ($this->translationRepository->findOneBy(['translateFrom' => 'Ja', 'translationTable' => 'caseTypeTable1']) ?? new Translation());
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Ja');
        $trans->setTranslateTo(true);
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->style) === true && $this->style->writeln('Translation created');

        $trans = ($this->translationRepository->findOneBy(['translateFrom' => 'internextern', 'translationTable' => 'caseTypeTable1']) ?? new Translation());
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('internextern');
        $trans->setTranslateTo('intern');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->style) === true && $this->style->writeln('Translation created');

        $trans = ($this->translationRepository->findOneBy(['translateFrom' => 'Vernietigen (V)', 'translationTable' => 'caseTypeTable1']) ?? new Translation());
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Vernietigen (V)');
        $trans->setTranslateTo('vernietigen');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->style) === true && $this->style->writeln('Translation created');

        $trans = ($this->translationRepository->findOneBy(['translateFrom' => 'Bewaren (B)', 'translationTable' => 'caseTypeTable1']) ?? new Translation());
        $trans->setTranslationTable('caseTypeTable1');
        $trans->setTranslateFrom('Bewaren (B)');
        $trans->setTranslateTo('blijvend_bewaren');
        $trans->setLanguage('nl');
        $this->entityManager->persist($trans);
        isset($this->style) === true && $this->style->writeln('Translation created');

    }//end createTranslations()
}//end class

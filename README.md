# XxllncZGWBundle [![Codacy Badge](https://app.codacy.com/project/badge/Grade/636ff2fbbcbd423dab24940ec99ad19e)](https://app.codacy.com/gh/CommonGateway/XxllncZGWBundle/dashboard?utm_source=gh\&utm_medium=referral\&utm_content=\&utm_campaign=Badge_grade)

The XxllncZGWBundle is a Symfony bundle that provides functionality for the CommonGateway about handling synchronizations between the xxllnc zaaksysteem standard and the ZGW standard. So it fetches and send xxllnc objects from and to their ZGW object equilevants.

For more information on ZaakGerichtWerken and XXLNC, please visit [here]([https://github.com/vrijBRP/vrijBRP]\(https://xxllnc.nl/zaakgericht\)https://xxllnc.nl/zaakgericht)

### Bundles Used in the XxllncZGWBundle

The [**XxllncZGWBundle**](https://github.com/CommonGateway/XxllncZGWBundle) currently utilizes the following bundles:

1. **CoreBundle**: [GitHub Repository](https://github.com/CommonGateway/CoreBundle)
2. **ZGWBundle**: [GitHub Repository](https://github.com/CommonGateway/BRPBundle) (the ZGWBundle also includes other bundles like the KlantenBundle which is part of ZGW.)

While the ZGW and CoreBundle can still be installed as standalone components (please refer to their respective installation guides), the XxllncZGWBundle now defaults to installing these bundles as additional plugins on the same gateway.

## Backend Installation Instructions

The XxllncZGWBundle backend codebase utilizes the Common Gateway as an open-source installation framework. This means that the XxllncZGWBundle library, in its core form, functions as a plugin on this Framework. To learn more about the Common Gateway, you can refer to the documentation [here](https://commongateway.readthedocs.io/en/latest/).

The CommonGateway Gateway UI frontend comes with te CommonGateway.

To install the backend, follow the steps below:

### Gateway Installation

1. If you do not have the Common Gateway installed, you can follow the installation guide provided [here](https://github.com/ConductionNL/commonground-gateway/tree/development#readme). The Common Gateway installation is required for the backend setup. You can choose any installation method for the gateway, such as Haven, Kubernetes, Linux, or Azure, and any database option like MySQL, PostgreSQL, Oracle, or MsSQL. The gateway framework handles this abstraction.

### XxllncZGWBundle Installation - Gateway UI

1. After successfully installing the Gateway, access the admin-ui and log in.
2. In the left menu, navigate to "Plugins" to view a list of installed plugins. If you don't find the "common-gateway/xxllnc-zgw-bundle" plugin listed here, you can search for it by clicking on "Search" in the upper-right corner and typing "xxllnc" in the search bar.
3. Click on the "common-gateway/xxllnc-zgw-bundle" card and then click on the "Install" button to install the plugin.
4. The admin-ui allows you to install, upgrade, or remove bundles. However, to load all the required data (schemas, endpoints, sources), you need to execute the initialization command in a php terminal.

### XxllncZGWBundle Installation - Terminal

1. Open a php terminal and run the following command to install the XxllncZGWBundle:

   ````
   ```cli
   $ composer require common-gateway/xxllnc-zgw-bundle
   ```
   ````

### Initialization Command (Terminal)

1. To load all the data without any specific content (like testdata), execute the following command:

   ````
   ```cli
   $ bin/console commongateway:initialize
   ```
   ````

   OR

   To load all the data along with specific content (like testdata), run:

   ````
   ```cli
   $ bin/console commongateway:initialize -data
   ```
   ````

With these steps completed, the backend setup for the XxllncZGW project should be ready to use. If you encounter any issues during the installation process, seek assistance from the development team. Happy coding!

## Gateway UI - Setup Instructions

Once the backend is up and running, the XxllncZGWBundle can be configured. To ensure proper functionality, the sources and Security Group (Default Anonymous user) need to be modified. Other adjustments are optional.

### Configuration Steps:

1. **Users**
   * Change the passwords of the users if necessary. It is recommended that you change the email of the admin user using [the following steps](https://github.com/CommonGateway/CoreBundle/tree/master/docs/work-instructions/user-management.md)

2. **Security Group**
   * Scopes have been added for the xxllnc user. You can view and adjust the scopes of the user via [the following steps](https://github.com/CommonGateway/CoreBundle/tree/master/docs/work-instructions/security-group-management.md):

3. **Sources**
   * Provide the required location and the API-Interface-ID and API-KEY (these two as headers) for the following source: xxllnc zaaksysteem

4. **Cronjob**
   * There is a cronjob which can be activated (check isEnabled) if all cases and casetypes need to be synced from the zaaksysteem source. Currently this is always disabled as we dont need all data from the xxllnc api.

Once you have completed these steps, the XxllncZGW Gateway UI should be fully configured and the project is ready to be used.

## Commands

To execute commands you need access to a PHP terminal.

There are some commands which can be used to synchronize some single or all objects from the xxllnc api.
The first you might want to use is the ZaakTypeCommand, it synchronizes a casetype to a ZGW ZaakType:

```cli
$ bin/console xxllnc:zaakType:synchronize id
```

If you need a certain case to a ZGW ZaakType you can also just execute the ZaakCommand and it will also synchronize its casetype to a ZGW ZaakType:

```cli
$ bin/console xxllnc:zaak:synchronize id
```

If you synchronized some zaaktypen and want to synchronize some specific besluittypen and link these to your earlier synchronized zaaktypen, you can first synchronize the casetypes to besluittypen with the ZaakTypeCommand (it will auto detect if its a ZaakType or BesluitType):

```cli
$ bin/console xxllnc:zaakType:synchronize id
```

And then you can link them to your earlier synchronized zaaktypen as besluittypen with the following command:

```cli
$ bin/console xxllnc:zaakType:connect:besluittype
```

These are all current commands, you can fetch your synchronized objects through the ZGW standard endpoints:
`/api/zrc/v1/zaken`
`/api/ztc/v1/zaaktypen`
`/api/ztc/v1/besluittypen`

## Synchronizations

There are a lot of objects being synced from and to the xxllnc zaaksysteem to zgw objects. Here is a table of them.

### From the zaaksysteem to the gateway:
| ZGW                  | Zaaksysteem    | Mapping                                                                                                                                     |
|----------------------|----------------|--------------------------------------------------------------------------------------------------------------------------------------------|
| BesluitType          | casetype       | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncBesluitTypeToZGWBesluitType.json)    |
| ZaakType             | casetype       | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncCaseTypeToZGWZaakType.json)          |
| StatusType           | phase          | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncPhaseToZGWStatusType.json)           |
| RolType              | role           | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncRoleToZGWRolType.json)               |
| ResultaatType        | result         | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncResultToZGWResultaatType.json)       |
| Eigenschap           | field          | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncFieldToZGWEigenschap.json)           |
| InformatieObjectType | field          | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncFieldToZGWInformatieObjectType.json) |
| Zaak                 | case           | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncCaseToZGWZaak.json)                  |
| Status               | milestone      | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncMilestoneToStatus.json)              |
| Resultaat            | outcome        | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncOutcomeToResultaat.json)             |
| Rol                  | role.requestor | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncRoleRequestorToRol.json)             |
| ZaakEigenschap       | attribute      | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncAttributeToZaakEigenschap.json)      |
| InformatieObject     | document       | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncDocumentToZaakInformatieObject.json) |

All above synchronizations are triggered by cronjob or command. Note that all child objects you see from Zaak and ZaakType are synced during synchronization of Zaak or ZaakType.
Also read [commands](#commands) on how to execute certain synchronizations.

#### Documents:
To fetch the documents of a case we have to do some extra api calls. 

First we fetch all documents from a case on the v2 api `/document/search_document?case_uuid={id}` endpoint, this gives us the document numbers/identifications (not id) belonging to the currently syncing case.

Foreach document we fetch the document metadata info on the v1 api `/document/get_by_number/` endpoint to get the id. With that id we fetch the actual document on the v2 api `/document/download_document?id={id}` endpoint.
Then we have all info and data to map the documents to ZGW informatieobjecten and add them to the mapped Zaak. 


### From the gateway to the zaaksysteem:
| Zaaksysteem | ZGW              | Trigger                                     | Mapping                                                                                                                       |
|-------------|------------------|---------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------|
| case        | Zaak             | POST/PUT /zrc/zaken                         | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/XxllncZaakToCase.json)       |
| case        | Besluit          | POST/PUT /brc/besluiten                     | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/ZgwBesluitToXxllncCase.json) |
| document    | InformatieObject | POST/PUT /zrc/zaken/id/zaakinformatieobject | [View on GitHub](https://github.com/CommonGateway/XxllncZGWBundle/blob/main/Installation/Mapping/ZgwBesluitToXxllncCase.json) |

Note: casetypes can't be created yet on the zaaksysteem api so we do not sync back ZaakTypen.

## Design decisions

Special noted decisions made in this project are:

* ZGW 'verlenging' is not mappable from a xxllnc case and thus ignored during synchronization.
* The xxllnc zaaksysteem does not ZGW BesluitTypen or Besluiten and thus there have been created 3 specific BesluitTypen as normal xxllnc casetypes which can be synced with the ZaakType command, so when a Besluit in the gateway is created for one of these 3 BesluitTypen we synchronize it back to xxllnc as a case and link it to the main case (Zaak) as related case.
* Casetypes can't be created/updated through the zaaksysteem api, so we do not sync ZaakTypen back to the zaaksysteem if they are created/updated on the gateway.

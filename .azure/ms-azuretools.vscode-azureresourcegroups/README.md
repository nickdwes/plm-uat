
The `authRecord.json` file is created after authenticating to an Azure subscription from Visual Studio Code (VS Code). For example, via the **Azure: Sign In** command in Command Palette. The directory in which the file resides matches the unique identifier of the [Azure Resources extension](https://marketplace.visualstudio.com/items?itemName=ms-azuretools.vscode-azureresourcegroups) responsible for writing the file.

### Purpose of `authRecord.json`

This file plays a key role in enabling a seamless single sign-on experience in the local development environment for VS Code customers. The file is used to persist a serialized representation of an [AuthenticationRecord](https://learn.microsoft.com/javascript/api/@azure/identity/authenticationrecord?view=azure-node-latest) object, which includes metadata about a previously authenticated user session. More specifically, the file:

- Allows products like the Azure Identity SDK and Azure MCP Server to reuse authentication state without prompting the user to sign in again.
- Enables the Azure Identity SDK's `DefaultAzureCredential()` chain to automatically authenticate users in dev loops, especially when running inside VS Code.

### What it contains

The file does **not** contain access tokens or secrets. This design avoids the security risks associated with storing sensitive credentials on disk. The table below describes the file's properties.

| Key             | Description                                                         |
|-----------------|---------------------------------------------------------------------|
| `authority`     | The Microsoft Entra authority used for authentication               |
| `clientId`      | The client ID of the app that performed the original authentication |
| `tenantId`      | The associated Microsoft Entra tenant ID                            |
| `username`      | The username of the logged in account                               |
| `homeAccountId` | A unique identifier for the account                                 |

### Security considerations

- The user profile's `.azure` directory is already used by other products, such as MSAL and Azure CLI to store metadata in `msal_token_cache.bin` and `azureProfile.json`, respectively.
- While `authRecord.json` itself isn't inherently dangerous, it should still be excluded from source control. A preconfigued `.gitignore` file is written alongside the file for that purpose.

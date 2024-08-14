# Orphan node handling for Neos CMS

This package provides a backend module to handle orphan nodes in Neos CMS 8.3 & 8.4.

⚠️ This package requires at least Neos 8.3.16 which contains a necessary patch so the module can return
relevant status codes for HTMX to consume.

## Screenshot

![Orphanage module](Documentation/OrphanageExample.png)

## Installation

Run the following command in your Neos project:

```shell
composer require shel/neos-orphanage
```

Or add the package as dependency to your site-package.

## Usage

You can access the orphanage module in the Neos backend under the "Management" section.

Features:

* Filter orphan nodes by node type
* Delete orphan nodes
* Delete all orphan nodes of a specific type
* Adopt orphan nodes to a specific page (see below)

### Adopting nodes

To adopt orphan nodes you will first need to allow the new document type 
`Shel.Neos.Orphanage:Document.Orphanage` to be created via constraints. F.e. on your homepage node.

Afterwards, orphan nodes can be adopted an will automatically be moved to the new page.

## Contribute

Contributions are very welcome.

For code contributions, please create a fork and create a PR against the lowest maintained
branch of this repository (currently `main`).

## License

See [License](LICENSE.txt)

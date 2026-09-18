# Client extraction provenance

The earlier component plan incorrectly assigned an external client and stdio bridge to `mcp_component`, under Composer name `joomengine/mcp`. The owner clarified that the external client belongs in this repository. The client package is now `joomengine/mcp-client`; the component's own dependency manifest is server-only under `joomengine/mcp-component`.

At extraction time there was no completed external MCP client to move. The only generic library files were `libraries/src/Http/CurlClient.php` and `NetworkException.php`, used by the component's server-side API adapter. Their original contents were inspected at `mcp_component@a3fb48c680c520fe3b81c6e40fc8aa8cc427f36e`; they were already present in `89353e8905bdfabe9f874d1f00e8cc9384c894e7`.

That transport foundation is retained here under `src/Http` and namespace `VDM\Joomla\Mcp\Client\Http`, with its original authorship/licence headers. The server independently keeps the required transport under `admin/src/Http` and its Administrator namespace; neither package imports the other's implementation. This deliberate small transport fork avoids making the installed server depend on its external client. A future shared infrastructure package would require a separately reviewed dependency decision.

External-client architecture, examples, remote bridge, release automation and interoperability status are owned here. Server docs now link to this handoff rather than prescribing client implementation in the component. The new Connection/ClientFactory/EndpointClient code supplies the actual standalone client foundation without copying a server catalogue.

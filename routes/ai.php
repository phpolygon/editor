<?php

use App\Mcp\Servers\EditorServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Servers
|--------------------------------------------------------------------------
|
| Local (stdio) MCP server that exposes the PHPolygon editor command bus as
| tools. Start it with `php artisan mcp:start editor`; a stdio client such as
| Claude Code connects to it via .mcp.json.
|
*/

Mcp::local('editor', EditorServer::class);

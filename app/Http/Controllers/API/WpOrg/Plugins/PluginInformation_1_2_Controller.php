<?php

namespace App\Http\Controllers\API\WpOrg\Plugins;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plugins\PluginInformationRequest;
use App\Http\Requests\Plugins\QueryPluginsRequest;
use App\Http\Resources\Plugins\PluginCollection;
use App\Http\Resources\Plugins\PluginResource;
use App\Services\Plugins\PluginHotTagsService;
use App\Services\Plugins\PluginInformationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PluginInformation_1_2_Controller extends Controller
{
    public function __construct(
        private readonly PluginInformationService $pluginService,
        private readonly PluginHotTagsService $hotTags,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return match ($request->query('action')) {
            'query_plugins' => $this->queryPlugins(new QueryPluginsRequest($request->all())),
            'plugin_information' => $this->pluginInformation(new PluginInformationRequest($request->all())),
            'hot_tags', 'popular_tags' => $this->hotTags($request),
            default => response()->json(['error' => 'Invalid action'], 400),
        };
    }

    private function pluginInformation(PluginInformationRequest $request): JsonResponse
    {
        $slug = $request->getSlug();
        if (!$slug) {
            return response()->json(['error' => 'Slug is required'], 400);
        }

        $plugin = $this->pluginService->findBySlug($request->getSlug());

        if (!$plugin) {
            return response()->json(['error' => 'Plugin not found'], 404);
        }

        return response()->json(new PluginResource($plugin));
    }

    private function queryPlugins(QueryPluginsRequest $request): JsonResponse
    {
        $result = $this->pluginService->queryPlugins(
            page: $request->getPage(),
            perPage: $request->getPerPage(),
            search: $request->query('search'),
            tag: $request->query('tag'),
            author: $request->query('author'),
            browse: $request->getBrowse()
        );

        return response()->json(new PluginCollection(
            PluginResource::collection($result['plugins']),
            $result['page'],
            $result['totalPages'],
            $result['total']
        ));
    }

    private function hotTags(Request $request): JsonResponse
    {
        $tags = $this->hotTags->getHotTags((int) $request->query('number', '-1'));
        return response()->json($tags);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Nvr;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Page shells only. Data comes from /api via Alpine components (resources/js/dashboard). */
class DashboardController extends Controller
{
    public function sites(): View
    {
        return view('dashboard.sites');
    }

    public function site(Request $request, Site $site): View
    {
        abort_unless($request->user()->canView($site->organization), 403);

        return view('dashboard.site', ['siteId' => $site->id, 'siteName' => $site->name]);
    }

    public function live(Request $request, Site $site): View
    {
        abort_unless($request->user()->canView($site->organization), 403);

        return view('dashboard.live', ['siteId' => $site->id, 'siteName' => $site->name]);
    }

    public function nvr(Request $request, Nvr $nvr): View
    {
        abort_unless($request->user()->canView($nvr->site->organization), 403);

        return view('dashboard.nvr', ['nvrId' => $nvr->id, 'nvrName' => $nvr->name]);
    }

    /** User guide: resources/guide/user-guide.md rendered with heading anchors and a contents list. */
    public function help(): View
    {
        $md = file_get_contents(resource_path('guide/user-guide.md'));
        $md = str_replace('{app}', config('app.name'), $md);
        $html = Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $toc = [];
        $html = preg_replace_callback('#<h2>(.*?)</h2>#', function ($m) use (&$toc) {
            $id = Str::slug(strip_tags($m[1]));
            $toc[] = ['id' => $id, 'title' => strip_tags($m[1])];

            return "<h2 id=\"{$id}\">{$m[1]}</h2>";
        }, $html);
        $html = preg_replace_callback('#<h3>(.*?)</h3>#', fn ($m) => '<h3 id="'.Str::slug(strip_tags($m[1])).'">'.$m[1].'</h3>', $html);

        return view('help', ['html' => $html, 'toc' => $toc]);
    }
}

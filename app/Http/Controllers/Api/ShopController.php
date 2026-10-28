<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreShopRequest;
use App\Http\Requests\Shop\UpdateCommissionRequest;
use App\Http\Requests\Shop\UpdateShopRequest;
use App\Http\Resources\ShopResource;
use App\Models\AdminLog;
use App\Models\Shop;
use App\Notifications\ShopReviewed;
use App\Services\CloudinaryService;
use App\Support\Notifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ShopController extends Controller
{
    public function index()
    {
        return Shop::where('statut', 'valide')
            ->whereHas('user', fn ($u) => $u->whereNull('suspendu_at'))
            ->when(config('services.stripe.connect_required'), fn ($q) => $q->where('is_active', true))
            ->select('id', 'nom', 'logo')->get();
    }

    public function store(StoreShopRequest $request, CloudinaryService $images)
    {
        $user = $request->user();
        if ($user->shop) return response()->json(['message' => 'Vous avez déjà une boutique'], 409);

        $data = [
            'user_id' => $user->id,
            'nom' => $request->nom,
            'description' => $request->description,
            'activite' => $request->activite,
            'statut' => 'en_attente',
        ];
        try {
            if ($request->hasFile('logo')) {
                $uploaded = $images->upload($request->file('logo'), 'shops');
                $data['logo'] = $uploaded['url'];
                $data['logo_public_id'] = $uploaded['public_id'];
            }
            $shop = Shop::create($data);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => "Impossible d'enregistrer le logo de la boutique."], 422);
        }

        return response()->json(ShopResource::make($shop)->resolve(), 201);
    }

    public function mine(Request $request)
    {
        $shop = $request->user()->shop;
        if (! $shop) return response()->json(['message' => "Vous n'avez pas encore de boutique"], 404);
        return response()->json(ShopResource::make($shop->load(['products.category', 'products.categories']))->resolve());
    }

    public function update(UpdateShopRequest $request, CloudinaryService $images)
    {
        // Requête fraîche : la relation peut être en cache (null) si la boutique vient d'être créée.
        $shop = $request->user()->shop()->first();
        if (! $shop) return response()->json(['message' => "Vous n'avez pas de boutique"], 404);
        $data = $request->safe()->only(['nom', 'description', 'activite']);
        // Boutique refusée : la modifier renvoie la demande à l'admin.
        if ($shop->statut === 'refuse') {
            $data['statut'] = 'en_attente';
            $data['motif_refus'] = null;
        }

        try {
            if ($request->hasFile('logo')) {
                $uploaded = $images->upload($request->file('logo'), 'shops');
                $images->delete($shop->logo_public_id);
                $this->deleteLocalImage($shop->logo);
                $data['logo'] = $uploaded['url'];
                $data['logo_public_id'] = $uploaded['public_id'];
            }
            $shop->update($data);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['message' => "Impossible de mettre à jour le logo de la boutique."], 422);
        }

        return response()->json(ShopResource::make($shop->fresh())->resolve());
    }

    public function adminIndex() { return ShopResource::collection(Shop::with('user')->latest()->get()); }
    public function validate(Shop $shop)
    {
        $shop->update(['statut' => 'valide', 'motif_refus' => null, 'valide_at' => now()]);
        AdminLog::record('boutique_validee', $shop, "Boutique « {$shop->nom} » validée");
        Notifier::send($shop->user, new ShopReviewed($shop));
        return response()->json(ShopResource::make($shop->load('user'))->resolve());
    }

    public function refuse(Request $request, Shop $shop)
    {
        $data = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:500']]);
        $shop->update(['statut' => 'refuse', 'motif_refus' => $data['motif'], 'valide_at' => null]);
        AdminLog::record('boutique_refusee', $shop, "Boutique « {$shop->nom} » refusée : {$data['motif']}");
        Notifier::send($shop->user, new ShopReviewed($shop));
        return response()->json(ShopResource::make($shop->load('user'))->resolve());
    }
    public function updateCommission(UpdateCommissionRequest $request, Shop $shop)
    {
        $ancienne = $shop->commission;
        $shop->update(['commission' => $request->commission]);
        AdminLog::record('commission_modifiee', $shop, "Commission de « {$shop->nom} » : {$ancienne} % → {$shop->commission} %");
        return response()->json(ShopResource::make($shop)->resolve());
    }

    private function deleteLocalImage(?string $url): void
    {
        if (! $url) return;
        $marker = '/storage/'; $pos = strpos($url, $marker);
        if ($pos === false) return;
        $relativePath = substr($url, $pos + strlen($marker));
        if (Storage::disk('public')->exists($relativePath)) Storage::disk('public')->delete($relativePath);
    }
}

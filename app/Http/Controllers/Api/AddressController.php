<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Address\SaveAddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Adresses de livraison de l'utilisateur connecté (acheteur, vendeur ou admin :
 * pas de restriction de rôle, chacun peut avoir des adresses sur son profil).
 */
class AddressController extends Controller
{
    // GET /api/addresses
    public function index(Request $request)
    {
        return AddressResource::collection(
            $request->user()->addresses()->orderByDesc('est_par_defaut')->latest()->get()
        );
    }

    // POST /api/addresses
    public function store(SaveAddressRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        // La toute première adresse devient automatiquement celle par défaut.
        $isFirst = ! $user->addresses()->exists();
        $data['est_par_defaut'] = $isFirst || (bool) ($data['est_par_defaut'] ?? false);

        $address = DB::transaction(function () use ($user, $data) {
            if ($data['est_par_defaut']) {
                $user->addresses()->update(['est_par_defaut' => false]);
            }

            return $user->addresses()->create($data);
        });

        return response()->json(AddressResource::make($address)->resolve(), 201);
    }

    // PUT /api/addresses/{address}
    public function update(SaveAddressRequest $request, Address $address)
    {
        if ((int) $address->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $data = $request->validated();

        DB::transaction(function () use ($address, $data) {
            if (! empty($data['est_par_defaut'])) {
                $address->user->addresses()->update(['est_par_defaut' => false]);
            }

            $address->update($data);
        });

        return response()->json(AddressResource::make($address->fresh())->resolve());
    }

    // DELETE /api/addresses/{address}
    public function destroy(Request $request, Address $address)
    {
        if ((int) $address->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $etaitParDefaut = $address->est_par_defaut;
        $address->delete();

        // Si l'adresse supprimée était celle par défaut, on en réassigne une autre.
        if ($etaitParDefaut) {
            $request->user()->addresses()->latest()->first()?->update(['est_par_defaut' => true]);
        }

        return response()->json(['message' => 'Adresse supprimée']);
    }

    // PUT /api/addresses/{address}/defaut
    public function setDefault(Request $request, Address $address)
    {
        if ((int) $address->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        DB::transaction(function () use ($address) {
            $address->user->addresses()->update(['est_par_defaut' => false]);
            $address->update(['est_par_defaut' => true]);
        });

        return response()->json(AddressResource::make($address->fresh())->resolve());
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\AdminLog;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Toutes les routes de ce contrôleur sont protégées par le middleware
// role:admin (voir routes/api.php) : plus de vérification manuelle ici.
class AdminController extends Controller
{
    // GET /api/admin/stats
    public function stats()
    {
        return response()->json([
            'total_utilisateurs' => User::count(),
            'total_acheteurs' => User::where('role', 'acheteur')->count(),
            'total_vendeurs' => User::where('role', 'vendeur')->count(),
            'total_livreurs' => User::where('role', 'livreur')->count(),
            // Frais de livraison des courses livrées moins la part des livreurs.
            'marge_livraison' => round((float) Delivery::where('statut', 'livree')->sum(DB::raw('frais - remuneration_livreur')), 2),
            'total_boutiques' => Shop::count(),
            'boutiques_en_attente' => Shop::where('statut', 'en_attente')->count(),
            'litiges_ouverts' => Delivery::whereNotNull('probleme_signale_at')->whereNull('litige_resolu_at')->count(),
            'total_produits' => Product::count(),
            'total_commandes' => Order::count(),
            // Argent réellement encaissé (voir OrderItem::scopeEncaissees) : les commandes carte
            // non payées ne comptent plus. (float) : sum() renvoie une chaîne via PDO.
            'chiffre_affaires_total' => round((float) OrderItem::encaissees()->sum(DB::raw('quantite * prix_unitaire')), 2),
            // Revenu de la plateforme : commissions figées sur les lignes encaissées.
            'commissions_total' => round((float) OrderItem::encaissees()->sum('commission_amount'), 2),
            // À traiter : virements vendeurs (Stripe) non effectués, rémunérations livreurs dues,
            // comptes suspendus.
            'virements_vendeurs_en_attente' => round((float) OrderItem::encaissees()
                ->whereIn('seller_transfer_status', ['pending', 'failed', 'blocked'])->sum('seller_amount'), 2),
            'virements_vendeurs_echoues' => OrderItem::encaissees()->where('seller_transfer_status', 'failed')->count(),
            'livreurs_a_payer' => round((float) Delivery::payables()->whereNull('remuneration_payee_at')->sum('remuneration_livreur'), 2),
            'comptes_suspendus' => User::whereNotNull('suspendu_at')->count(),
        ]);
    }

    // GET /api/admin/livreurs : activité et rémunération restant à verser à chaque livreur.
    public function livreurs()
    {
        $livreurs = User::where('role', 'livreur')
            ->withCount(['deliveries as courses_livrees' => fn ($q) => $q->where('statut', 'livree')])
            ->withCount(['deliveries as courses_en_cours' => fn ($q) => $q->whereIn('statut', ['acceptee', 'en_route'])])
            ->withSum(['deliveries as gains_total' => fn ($q) => $q->where('statut', 'livree')], 'remuneration_livreur')
            ->withSum(['deliveries as a_payer' => fn ($q) => $q->payables()->whereNull('remuneration_payee_at')], 'remuneration_livreur')
            ->withCount(['deliveries as litiges' => fn ($q) => $q->whereNotNull('probleme_signale_at')->whereNull('litige_resolu_at')])
            ->orderBy('name')
            ->get();

        return response()->json($livreurs->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'telephone' => $u->telephone,
            'ville' => $u->ville,
            'courses_livrees' => (int) $u->courses_livrees,
            'courses_en_cours' => (int) $u->courses_en_cours,
            'gains_total' => round((float) $u->gains_total, 2),
            'a_payer' => round((float) $u->a_payer, 2),
            'litiges' => (int) $u->litiges,
        ])->values());
    }

    // POST /api/admin/livreurs/{user}/payer : marque comme versée la rémunération due.
    public function payLivreur(User $user)
    {
        if ($user->role !== 'livreur') {
            return response()->json(['message' => "Cet utilisateur n'est pas livreur."], 422);
        }

        // Seulement les courses dont le client a confirmé la réception, sans litige.
        $montant = (float) $user->deliveries()->payables()->whereNull('remuneration_payee_at')->sum('remuneration_livreur');
        $user->deliveries()->payables()->whereNull('remuneration_payee_at')->update(['remuneration_payee_at' => now()]);
        AdminLog::record('livreur_paye', $user, "Rémunération versée à {$user->name} : ".number_format($montant, 2, ',', ' ').' MAD');

        return response()->json(['message' => 'Rémunération marquée comme versée.', 'montant' => round($montant, 2)]);
    }

    // GET /api/admin/users?role=vendeur|suspendu&q=...&page=2 (25 par page)
    public function users(Request $request)
    {
        $filtre = $request->query('role');
        $q = trim((string) $request->query('q', ''));

        $users = User::with('shop:id,user_id,nom,statut')->withCount('orders')
            ->when($filtre === 'suspendu', fn ($query) => $query->whereNotNull('suspendu_at'))
            ->when(in_array($filtre, ['acheteur', 'vendeur', 'livreur', 'admin'], true), fn ($query) => $query->where('role', $filtre))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhere('ville', 'like', "%{$q}%")
                ->orWhereHas('shop', fn ($s) => $s->where('nom', 'like', "%{$q}%"))))
            ->latest()
            ->paginate(25);

        // Compteurs des onglets (tous les comptes, sans la recherche) : une seule requête.
        $parRole = User::selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role');

        return response()->json([
            'data' => UserResource::collection($users->items())->resolve(),
            'meta' => ['page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
            'counts' => [
                'tous' => (int) $parRole->sum(),
                ...collect(['acheteur', 'vendeur', 'livreur', 'admin'])->mapWithKeys(fn ($r) => [$r => (int) ($parRole[$r] ?? 0)]),
                'suspendu' => User::whereNotNull('suspendu_at')->count(),
            ],
        ]);
    }

    // GET /api/admin/journal?action=...&page=2 (30 par page)
    public function journal(Request $request)
    {
        $logs = AdminLog::with('admin:id,name')
            ->when($request->query('action'), fn ($q, $action) => $q->where('action', $action))
            ->latest('id')
            ->paginate(30);

        return response()->json([
            'data' => collect($logs->items())->map(fn (AdminLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'cible_type' => $log->cible_type,
                'cible_id' => $log->cible_id,
                'admin' => $log->admin?->name ?? 'Admin supprimé',
                'created_at' => $log->created_at,
            ]),
            'meta' => ['page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }

    // POST /api/admin/users : création d'un compte (tous rôles, admin compris).
    public function storeUser(StoreUserRequest $request)
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);
        AdminLog::record('utilisateur_cree', $user, "Compte {$user->role} créé : {$user->name} ({$user->email})");

        return response()->json(
            UserResource::make($user->load('shop:id,user_id,nom,statut')->loadCount('orders'))->resolve(),
            201
        );
    }

    // PUT /api/admin/users/{user} : modification d'un compte.
    public function updateUser(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();

        if ($data['role'] !== $user->role) {
            // Changer de rôle laisserait des données orphelines (boutique, courses) ou couperait l'accès admin.
            $blocage = match (true) {
                $user->id === $request->user()->id => 'Vous ne pouvez pas changer votre propre rôle.',
                $user->shop()->exists() => 'Ce vendeur a une boutique : son rôle ne peut pas être changé.',
                $user->deliveries()->exists() => 'Ce livreur a déjà des courses : son rôle ne peut pas être changé.',
                default => null,
            };
            if ($blocage) {
                return response()->json(['message' => $blocage], 422);
            }
        }

        if ($data['password'] ?? null) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->fill($data);
        // Champs réellement modifiés, pour le journal (sans la valeur du mot de passe).
        $modifies = collect(array_keys($user->getDirty()))
            ->map(fn ($champ) => match ($champ) {
                'role' => "rôle → {$user->role}",
                'password' => 'mot de passe',
                'name' => 'nom',
                default => $champ,
            })
            ->implode(', ');
        $user->save();
        if ($modifies !== '') {
            AdminLog::record('utilisateur_modifie', $user, "Compte de {$user->name} modifié : {$modifies}");
        }

        // Nouveau mot de passe : les sessions ouvertes de l'utilisateur sont fermées.
        if (isset($data['password']) && $user->id !== $request->user()->id) {
            $user->tokens()->delete();
        }

        return response()->json(UserResource::make($user->load('shop:id,user_id,nom,statut')->loadCount('orders'))->resolve());
    }

    // POST /api/admin/users/{user}/suspendre : bloque la connexion et ferme les sessions ouvertes.
    // Les produits d'un vendeur suspendu disparaissent du catalogue (Product::scopeVisible).
    public function suspend(Request $request, User $user)
    {
        $data = $request->validate(['motif' => ['required', 'string', 'min:5', 'max:500']]);
        if ($user->role === 'admin') {
            return response()->json(['message' => 'Un compte administrateur ne peut pas être suspendu.'], 422);
        }

        $user->update(['suspendu_at' => now(), 'motif_suspension' => $data['motif']]);
        $user->tokens()->delete();
        AdminLog::record('utilisateur_suspendu', $user, "Compte de {$user->name} suspendu : {$data['motif']}");

        return response()->json(UserResource::make($user->load('shop:id,user_id,nom,statut')->loadCount('orders'))->resolve());
    }

    // POST /api/admin/users/{user}/reactiver
    public function reactivate(User $user)
    {
        $user->update(['suspendu_at' => null, 'motif_suspension' => null]);
        AdminLog::record('utilisateur_reactive', $user, "Compte de {$user->name} réactivé");

        return response()->json(UserResource::make($user->load('shop:id,user_id,nom,statut')->loadCount('orders'))->resolve());
    }
}
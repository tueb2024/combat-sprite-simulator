# Administration du site (jumphp.net)

Réservée au compte Git de l'administrateur (`User::ADMIN_GITHUB_ID`, l'identifiant Git, pas le login). Page `/studio/admin` (bouton « Administration » de « Mes jeux »).
L'administrateur voit **tous** les projets (même non listés ou hors ligne) et peut :

- **Demandes TCP / SSH** : autoriser ou refuser (une autorisation vaut pour un commit précis), voir et révoquer ce qui est autorisé (docs/tcp.md).
- **Pont TCP, en direct** (rafraîchi toutes les 2 secondes) : connexions ouvertes, débit, qui demande quoi (application, cible, réponse), journal (le plus récent en haut),
  alertes (quota de bande passante dépassé) ; couper une connexion, bloquer une application ou une adresse (une heure), débloquer.
- **Projets** (recherche par titre, identifiant ou auteur) : dépublier / publier, retirer de l'annuaire / lister, changer l'étape (dev, beta, done), **supprimer**
  (fichiers du dépôt local, du publié et privés compris ; le dépôt Git de l'auteur n'est pas touché).
- **Utilisateurs** : **bannir** (avec une raison : plus de connexion Git, les sessions et les jetons IDE / MCP s'arrêtent à la requête suivante), débloquer,
  **supprimer** le compte et ses projets (un compte lié à d'autres données — commentaires, notes... — est banni à la place). Le compte administrateur ne peut être ni banni ni supprimé.

Sauvegardes à garder (docs/tcp.md) : le compte Git de l'administrateur, `TOKEN_KEY` et le reste de `deploy/.env`, la base de données.

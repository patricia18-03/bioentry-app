-- ============================================================
-- Migration : nettoyage des doublons existants + contrainte
-- anti-doublon sur la table pointages.
-- À exécuter UNE SEULE FOIS dans phpMyAdmin (onglet "SQL"),
-- sur une base bioentry_pointage déjà créée avec l'ancien schéma.
-- ============================================================

USE bioentry_pointage;

-- 1) Supprime les doublons déjà présents (garde la ligne la plus ancienne)
DELETE p1 FROM pointages p1
INNER JOIN pointages p2
    ON p1.id > p2.id
   AND p1.date_heure = p2.date_heure
   AND p1.utilisateur_nom = p2.utilisateur_nom
   AND (p1.utilisateur_matricule <=> p2.utilisateur_matricule)
   AND p1.equipement_nom = p2.equipement_nom
   AND p1.porte = p2.porte
   AND p1.evenement = p2.evenement;

-- 2) Vide les anomalies (elles seront recalculées au prochain import/affichage)
TRUNCATE TABLE anomalies;

-- 3) Empêche tout nouveau doublon exact d'être inséré (utilisé par
--    l'import via INSERT IGNORE)
ALTER TABLE pointages
    ADD UNIQUE KEY uniq_pointage (date_heure, utilisateur_matricule, utilisateur_nom, equipement_nom, porte, evenement);

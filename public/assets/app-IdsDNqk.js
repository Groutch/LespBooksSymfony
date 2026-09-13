/*
 * Point d'entree charge par `importmap('app')` dans `base.html.twig`.
 *
 * L'import du CSS est ce qui declenche la compilation Tailwind et l'emission
 * de la balise <link> par AssetMapper : sans lui, le site sort sans style.
 */
import './stimulus_bootstrap.js';
import './styles/app.css';

import './bootstrap';
import Alpine from 'alpinejs';
import { sitesPage } from './dashboard/sites';
import { sitePage } from './dashboard/site';
import { nvrPage } from './dashboard/nvr';
import { cameraTile, livePage } from './dashboard/live';
import * as ui from './dashboard/ui';

window.ui = ui;
Alpine.data('sitesPage', sitesPage);
Alpine.data('sitePage', sitePage);
Alpine.data('nvrPage', nvrPage);
Alpine.data('livePage', livePage);
Alpine.data('cameraTile', cameraTile);
window.Alpine = Alpine;
Alpine.start();

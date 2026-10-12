import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App';
import { PHP_VERSION_MARKER } from './phpVersionMarker';
import './index.css';

document.body.classList.toggle('php-version-marker', PHP_VERSION_MARKER);

const basename = import.meta.env.BASE_URL.replace(/\/$/, '');

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <BrowserRouter basename={basename || undefined}>
      <App />
    </BrowserRouter>
  </React.StrictMode>
);

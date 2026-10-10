// Tunnel https (scripts/servir-tunnel.mjs) : le front et l'API sont servis par
// la meme adresse https ; l'API est donc appelee en relatif (pas de contenu
// mixte http/https, qui serait bloque par le navigateur).
export const environment = {
  production: true,
  apiUrl: '/api',
};

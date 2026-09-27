// En dev, l'API est toujours servie sur le port 8000 de la MEME machine que
// celle qui sert le front (127.0.0.1 en local, IP LAN quand on teste depuis
// un telephone/tablette). On derive donc l'hote de window.location plutot que
// de coder une adresse en dur, qui ne fonctionnerait que sur un seul appareil.
const hoteApi = typeof window !== 'undefined' ? window.location.hostname : 'localhost';

export const environment = {
  production: false,
  apiUrl: `http://${hoteApi}:8000/api`,
};

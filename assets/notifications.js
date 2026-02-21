/**
 * Raoul Lenoir - System de Notifications
 * Gère les rappels de saisie des heures
 */

class NotificationManager {
    constructor() {
        this.checkInterval = 30000; // 30 secondes
        this.lastNotificationDate = null;
        this.init();
    }

    async init() {
        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
            console.warn('Notifications non supportées sur ce navigateur.');
            return;
        }

        // Register Service Worker
        try {
            this.registration = await navigator.serviceWorker.register('../sw.js', { scope: '../' });
            console.log('Service Worker enregistré avec succès');
        } catch (err) {
            console.error('Erreur lors de l\'enregistrement du Service Worker:', err);
        }

        this.requestPermission();
        this.startTimer();
    }

    async requestPermission() {
        if (Notification.permission === 'default') {
            const permission = await Notification.requestPermission();
            if (permission === 'granted') {
                console.log('Permission de notification accordée');
            }
        }
    }

    startTimer() {
        setInterval(() => this.checkSchedule(), this.checkInterval);
        // Premier check immédiat
        this.checkSchedule();
    }

    checkSchedule() {
        const now = new Date();
        const day = now.getDay(); // 0: Dimanche, 1: Lundi, ..., 5: Vendredi, 6: Samedi
        const hours = now.getHours();
        const minutes = now.getMinutes();

        // Éviter les notifications multiples le même jour/heure
        const todayStr = now.toDateString();
        if (this.lastNotificationDate === todayStr) return;

        let shouldNotify = false;
        let message = "";

        // Lundi (1) à Jeudi (4) à 16h00
        if (day >= 1 && day <= 4) {
            if (hours === 16 && minutes === 0) {
                shouldNotify = true;
                message = "Pensez à saisir vos heures travaillées aujourd'hui !";
            }
        }
        // Vendredi (5) à 11h00
        else if (day === 5) {
            if (hours === 11 && minutes === 0) {
                shouldNotify = true;
                message = "Saisissez vos heures avant le week-end !";
            }
        }

        if (shouldNotify) {
            this.sendNotification("Rappel de Pointage", message);
            this.lastNotificationDate = todayStr;
        }
    }

    sendNotification(title, message) {
        if (Notification.permission === 'granted') {
            if (navigator.serviceWorker.controller) {
                navigator.serviceWorker.controller.postMessage({
                    type: 'SHOW_NOTIFICATION',
                    title: title,
                    message: message
                });
            } else {
                // Fallback si pas de controller (rare)
                new Notification(title, { body: message, icon: '../assets/icon-192.png' });
            }
        }
    }
}

// Initialisation au chargement
window.addEventListener('load', () => {
    window.notificationManager = new NotificationManager();
});

/**
 * Système de notification - Rappel pointage
 * - Lundi à Jeudi : notification à 16h00
 * - Vendredi : notification à 11h00
 * - Samedi/Dimanche : aucune notification
 */

const NotifManager = {
    // Clé pour stocker si la notif du jour a déjà été affichée
    STORAGE_KEY: 'pointage_notif_shown_',
    
    // Heures de notification par jour (0=dimanche, 1=lundi ... 6=samedi)
    SCHEDULE: {
        0: null,    // Dimanche - pas de notif
        1: 16,      // Lundi - 16h
        2: 16,      // Mardi - 16h
        3: 16,      // Mercredi - 16h
        4: 16,      // Jeudi - 16h
        5: 11,      // Vendredi - 11h
        6: null,    // Samedi - pas de notif
    },
    
    init() {
        // Demander la permission au premier lancement
        this.requestPermission();
        
        // Vérifier toutes les 30 secondes
        this.check();
        setInterval(() => this.check(), 30000);
        
        // Afficher le statut dans la console
        this.logStatus();
    },
    
    async requestPermission() {
        if (!('Notification' in window)) {
            console.log('[Pointage] Notifications non supportées par ce navigateur');
            return false;
        }
        
        if (Notification.permission === 'default') {
            const permission = await Notification.requestPermission();
            console.log('[Pointage] Permission notifications:', permission);
            return permission === 'granted';
        }
        
        return Notification.permission === 'granted';
    },
    
    getTodayKey() {
        const today = new Date();
        return this.STORAGE_KEY + today.toISOString().split('T')[0];
    },
    
    wasShownToday() {
        try {
            return sessionStorage.getItem(this.getTodayKey()) === 'true';
        } catch {
            return false;
        }
    },
    
    markAsShown() {
        try {
            sessionStorage.setItem(this.getTodayKey(), 'true');
        } catch {}
    },
    
    check() {
        const now = new Date();
        const day = now.getDay();
        const hour = now.getHours();
        const minutes = now.getMinutes();
        
        const targetHour = this.SCHEDULE[day];
        
        // Pas de notification ce jour
        if (targetHour === null) return;
        
        // Vérifier si c'est l'heure (fenêtre de 30 minutes)
        if (hour === targetHour && minutes < 30 && !this.wasShownToday()) {
            this.showNotification(day === 5);
            this.showInAppNotification(day === 5);
            this.markAsShown();
        }
    },
    
    showNotification(isFriday) {
        if (Notification.permission !== 'granted') return;
        
        const title = '⏱ Rappel Pointage';
        const body = isFriday
            ? 'Il est 11h ce vendredi — saisissez vos heures avant le week-end !'
            : 'Il est 16h — pensez à saisir vos heures travaillées aujourd\'hui.';
        
        try {
            const notif = new Notification(title, {
                body: body,
                icon: 'assets/icon-192.png',
                badge: 'assets/icon-192.png',
                tag: 'pointage-rappel',
                requireInteraction: true,
                vibrate: [200, 100, 200],
            });
            
            notif.onclick = () => {
                window.focus();
                notif.close();
            };
            
            // Fermer après 30 secondes
            setTimeout(() => notif.close(), 30000);
        } catch (e) {
            console.log('[Pointage] Erreur notification:', e);
        }
    },
    
    showInAppNotification(isFriday) {
        // Notification in-app (bandeau)
        const existing = document.getElementById('notif-rappel');
        if (existing) existing.remove();
        
        const banner = document.createElement('div');
        banner.id = 'notif-rappel';
        banner.innerHTML = `
            <div style="
                position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
                background: linear-gradient(135deg, #f59e0b, #d97706);
                color: #0a0f1a; padding: 14px 20px;
                display: flex; align-items: center; justify-content: space-between;
                font-family: 'JetBrains Mono', monospace; font-size: 13px; font-weight: 600;
                box-shadow: 0 4px 20px rgba(245, 158, 11, 0.4);
                animation: slideDown 0.4s ease;
            ">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-size: 20px;">🔔</span>
                    <span>${isFriday
                        ? 'Vendredi 11h — Saisissez vos heures avant le week-end !'
                        : '16h — Pensez à saisir vos heures aujourd\'hui !'
                    }</span>
                </div>
                <button onclick="this.closest('#notif-rappel').remove()" style="
                    background: rgba(0,0,0,0.15); border: none; color: #0a0f1a;
                    width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
                    font-size: 14px; font-weight: bold; display: flex; align-items: center;
                    justify-content: center;
                ">✕</button>
            </div>
        `;
        
        document.body.prepend(banner);
        
        // Auto-dismiss après 60 secondes
        setTimeout(() => {
            if (banner.parentNode) {
                banner.style.transition = 'opacity 0.5s';
                banner.style.opacity = '0';
                setTimeout(() => banner.remove(), 500);
            }
        }, 60000);
    },
    
    // Pour test manuel
    testNotification(isFriday = false) {
        this.showNotification(isFriday);
        this.showInAppNotification(isFriday);
    },
    
    logStatus() {
        const now = new Date();
        const day = now.getDay();
        const jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
        const targetHour = this.SCHEDULE[day];
        
        console.log(`[Pointage] ${jours[day]} — Notification prévue : ${targetHour !== null ? targetHour + 'h00' : 'aucune'}`);
        console.log(`[Pointage] Permission navigateur : ${Notification.permission}`);
        console.log(`[Pointage] Déjà affichée aujourd'hui : ${this.wasShownToday()}`);
    }
};

// CSS pour l'animation
const style = document.createElement('style');
style.textContent = `
    @keyframes slideDown {
        from { transform: translateY(-100%); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }
`;
document.head.appendChild(style);

// Démarrer le système
document.addEventListener('DOMContentLoaded', () => NotifManager.init());

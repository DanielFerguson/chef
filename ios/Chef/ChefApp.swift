import SwiftUI

@main
struct ChefApp: App {
    @StateObject private var appState = AppState()
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(appState)
                .onChange(of: scenePhase) { _, phase in
                    if phase == .active {
                        appState.resumePollingIfNeeded()
                    } else {
                        appState.stopPolling()
                    }
                }
        }
    }
}

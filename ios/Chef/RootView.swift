import SwiftUI

struct RootView: View {
    @EnvironmentObject private var appState: AppState

    var body: some View {
        Group {
            if appState.isRestoringSession {
                ProgressView("Opening Chef")
                    .controlSize(.large)
            } else if appState.isAuthenticated {
                PlanListView()
            } else {
                SignInView()
            }
        }
        .alert(
            "Chef",
            isPresented: Binding(
                get: { appState.errorMessage != nil },
                set: { isPresented in
                    if !isPresented {
                        appState.clearError()
                    }
                }
            ),
            actions: {
                Button("OK") {
                    appState.clearError()
                }
            },
            message: {
                Text(appState.errorMessage ?? "")
            }
        )
    }
}

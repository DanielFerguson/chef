import ChefAPI
import SwiftUI

struct ColesConnectionView: View {
    @EnvironmentObject private var appState: AppState
    @Environment(\.dismiss) private var dismiss
    @State private var standingConsentAccepted = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    if let liveSession = appState.liveSession {
                        BrowserbaseLiveView(url: liveSession.liveViewUrl)
                            .frame(minHeight: 420)
                            .clipShape(RoundedRectangle(cornerRadius: 12))
                            .overlay {
                                RoundedRectangle(cornerRadius: 12)
                                    .stroke(.quaternary)
                            }
                            .privacySensitive()
                            .accessibilityLabel("Interactive Coles sign-in browser")

                        EphemeralLiveInputRelay()

                        if let consent = appState.liveConsent {
                            Divider()

                            Text("Standing consent")
                                .font(.title3.weight(.semibold))

                            Text(consent.disclosure)
                                .foregroundStyle(.secondary)

                            consentLinks(consent)

                            Toggle(
                                "I am the Coles account owner and accept this standing consent.",
                                isOn: $standingConsentAccepted
                            )

                            Button {
                                Task {
                                    await appState.verifyColesConnection(
                                        consentAccepted: standingConsentAccepted
                                    )

                                    if appState.liveSession == nil {
                                        dismiss()
                                    }
                                }
                            } label: {
                                HStack {
                                    Spacer()
                                    if appState.isWorking {
                                        ProgressView()
                                    } else {
                                        Text("Verify sign-in & continue")
                                    }
                                    Spacer()
                                }
                            }
                            .buttonStyle(.borderedProminent)
                            .disabled(!standingConsentAccepted || appState.isWorking)
                        }
                    } else {
                        ContentUnavailableView {
                            Label("Connect Coles", systemImage: "person.crop.circle.badge.checkmark")
                        } description: {
                            Text("Sign in through a short-lived Browserbase Live View. Chef never stores your Coles password.")
                        } actions: {
                            Button("Open secure sign-in") {
                                Task {
                                    await appState.startColesConnection()
                                }
                            }
                            .buttonStyle(.borderedProminent)
                            .disabled(appState.isWorking)
                        }
                    }
                }
                .padding()
            }
            .navigationTitle("Connect Coles")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") {
                        Task {
                            await appState.releaseLiveView()
                            dismiss()
                        }
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func consentLinks(_ consent: ConsentDisclosure) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            if
                let rawURL = consent.links.colesOnlineSafety,
                let url = URL(string: rawURL)
            {
                Link("Coles online-safety guidance", destination: url)
            }

            if
                let rawURL = consent.links.colesCustomerAgreement,
                let url = URL(string: rawURL)
            {
                Link("Coles Customer Agreement", destination: url)
            }
        }
    }
}

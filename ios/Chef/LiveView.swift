import SwiftUI
import WebKit

struct BrowserbaseLiveView: UIViewRepresentable {
    let url: URL

    func makeUIView(context: Context) -> WKWebView {
        let configuration = WKWebViewConfiguration()
        configuration.websiteDataStore = .nonPersistent()
        configuration.defaultWebpagePreferences.allowsContentJavaScript = true

        let webView = WKWebView(frame: .zero, configuration: configuration)
        webView.allowsBackForwardNavigationGestures = false
        webView.scrollView.contentInsetAdjustmentBehavior = .never
        webView.load(URLRequest(url: url))
        return webView
    }

    func updateUIView(_ webView: WKWebView, context: Context) {
        guard webView.url != url else {
            return
        }

        webView.load(URLRequest(url: url))
    }
}

struct EphemeralLiveInputRelay: View {
    @EnvironmentObject private var appState: AppState
    @State private var input = ""

    private let keys = ["Tab", "Enter", "Backspace"]

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("Mobile keyboard relay")
                .font(.headline)

            Text("Tap the field in the Coles view, then enter text here. Chef forwards it once to the active browser and immediately clears this field.")
                .font(.footnote)
                .foregroundStyle(.secondary)

            HStack {
                SecureField("Text for the focused Coles field", text: $input)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .privacySensitive()
                    .onSubmit(send)

                Button("Send", action: send)
                    .disabled(input.isEmpty || appState.isRelayingInput)
            }

            HStack {
                ForEach(keys, id: \.self) { key in
                    Button(key) {
                        Task {
                            await appState.relayKey(key)
                        }
                    }
                    .buttonStyle(.bordered)
                    .disabled(appState.isRelayingInput)
                }

                if appState.isRelayingInput {
                    ProgressView()
                        .controlSize(.small)
                }
            }
        }
        .accessibilityElement(children: .contain)
    }

    private func send() {
        guard !input.isEmpty else {
            return
        }

        let value = input
        input = ""

        Task {
            await appState.relayText(value)
        }
    }
}
